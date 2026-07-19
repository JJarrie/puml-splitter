<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Graph\SeedsPartitioner;
use PumlSplitter\Graph\SeedsPartitionResult;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Tests\Support\GraphFactory;

/**
 * §9 "Stratégies M7+" seeds coverage: both tie-break levels made individually
 * decisive, multi-source simultaneity (a case where sequential "graine par
 * graine" claiming would give a different, wrong answer), auto-selection
 * (threshold + hub exclusion), the zero-seed degenerate case, and
 * determinism. Written before the BFS implementation — see plan/CLAUDE.md's
 * TDD instruction for this milestone.
 */
#[CoversClass(SeedsPartitioner::class)]
final class SeedsPartitionerTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                      $explicitSeeds
     *
     * @return array{0: SeedsPartitioner, 1: Graph, 2: Document}
     */
    private function partitioner(
        array $edges,
        array $explicitSeeds = [],
        int $seedThreshold = 7,
        int $hubInThreshold = 8,
        int $hubOutThreshold = 20,
        int $minSize = 1,
        int $maxSize = 25,
    ): array {
        return $this->partitionerExcluding($edges, [], $explicitSeeds, $seedThreshold, $hubInThreshold, $hubOutThreshold, $minSize, $maxSize);
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                      $excludedFromHubs
     * @param list<string>                      $explicitSeeds
     *
     * @return array{0: SeedsPartitioner, 1: Graph, 2: Document}
     */
    private function partitionerExcluding(
        array $edges,
        array $excludedFromHubs,
        array $explicitSeeds = [],
        int $seedThreshold = 7,
        int $hubInThreshold = 8,
        int $hubOutThreshold = 20,
        int $minSize = 1,
        int $maxSize = 25,
    ): array {
        $document = GraphFactory::document($edges);
        $graph = Graph::fromDocument($document);

        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }
        $fallback = new AutoClusterer(new PrefixClusterer($shortNames, $maxSize), new LouvainClusterer($graph), $graph, $maxSize);

        $partitioner = new SeedsPartitioner(
            new HubDetector($hubInThreshold, $hubOutThreshold, [], HubPolicy::Duplicate, [], $excludedFromHubs),
            $fallback,
            new ClusterRefiner($minSize, $maxSize),
            $explicitSeeds,
            $seedThreshold,
        );

        return [$partitioner, $graph, $document];
    }

    private function succeeded(SeedsPartitionResult $result): Partition
    {
        self::assertFalse($result->isFatal(), (string) $result->fatalError);
        self::assertNotNull($result->partition);

        return $result->partition;
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function findCluster(array $clusters, string $name): ?Cluster
    {
        foreach ($clusters as $cluster) {
            if ($cluster->name === $name) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function ownerOf(array $clusters, string $alias): ?string
    {
        foreach ($clusters as $cluster) {
            if ($cluster->contains($alias)) {
                return $cluster->name;
            }
        }

        return null;
    }

    /**
     * Chain A-P-Q-X (A to X is 3 hops) plus a direct edge B-X (1 hop).
     * X is unambiguously closer to B. A "graine par graine" implementation
     * that lets seed A greedily claim every node it can reach before B gets
     * a turn would wrongly assign X to A (A's unrestricted BFS reaches X
     * eventually, and a first-come claim never revisits it). True
     * simultaneous multi-source BFS must give X to B.
     */
    public function testMultiSourceBfsIsSimultaneousNotSeedBySeed(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'P'], ['P', 'Q'], ['Q', 'X'], ['B', 'X']],
            explicitSeeds: ['A', 'B'],
        );

        $partition = $this->succeeded($partitioner->partition($graph, $document));

        self::assertSame('B', $this->ownerOf($partition->clusters, 'X'));
    }

    /**
     * Y is at distance 2 from both A (via P1 or P2) and B (via P3) — tied.
     * Tie-break level 1 counts, for each candidate seed, how many of Y's
     * direct neighbours sit one hop closer to that seed (a shortest-path
     * predecessor). Y has two such neighbours toward A (P1, P2) but only one
     * toward B (P3), so A must win without ever consulting alphabetical order.
     */
    public function testTieBreakLevelOneEdgeCountIsDecisive(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'P1'], ['A', 'P2'], ['B', 'P3'], ['Y', 'P1'], ['Y', 'P2'], ['Y', 'P3']],
            explicitSeeds: ['A', 'B'],
        );

        $partition = $this->succeeded($partitioner->partition($graph, $document));

        self::assertSame('A', $this->ownerOf($partition->clusters, 'Y'));
    }

    /**
     * Z is at distance 2 from both S1 (via M) and S2 (via N) — tied — AND
     * the level-1 predecessor count is also tied (one qualifying neighbour
     * each: M for S1, N for S2). Only alphabetical order (S1 < S2) can
     * resolve it.
     */
    public function testTieBreakLevelTwoAlphabeticalIsDecisiveWhenLevelOneAlsoTies(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['S1', 'M'], ['M', 'Z'], ['S2', 'N'], ['N', 'Z']],
            explicitSeeds: ['S2', 'S1'],
        );

        $partition = $this->succeeded($partitioner->partition($graph, $document));

        self::assertSame('S1', $this->ownerOf($partition->clusters, 'Z'));
    }

    /**
     * Auto-selection: Root (out-degree 3, non-hub) qualifies at threshold 3.
     * Hub (out-degree 4, but also in-degree 3 so it's a hub) must NOT be
     * auto-selected even though its out-degree alone would qualify. Leaf
     * (out-degree 1) doesn't meet the threshold.
     */
    public function testAutoSelectionUsesThresholdAndExcludesHubs(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [
                ['X1', 'Hub'], ['X2', 'Hub'], ['X3', 'Hub'],
                ['Hub', 'Y1'], ['Hub', 'Y2'], ['Hub', 'Y3'], ['Hub', 'Y4'],
                ['Root', 'Z1'], ['Root', 'Z2'], ['Root', 'Z3'],
                ['Leaf', 'W1'],
            ],
            explicitSeeds: [],
            seedThreshold: 3,
            hubInThreshold: 3,
            hubOutThreshold: 100,
        );

        $partition = $this->succeeded($partitioner->partition($graph, $document));

        $hubAliases = array_map(static fn ($h) => $h->alias, $partition->hubs);
        self::assertContains('Hub', $hubAliases);

        $root = $this->findCluster($partition->clusters, 'Root');
        self::assertNotNull($root);
        self::assertContains('Z1', $root->members);

        self::assertNull($this->findCluster($partition->clusters, 'Hub'));
        self::assertNull($this->findCluster($partition->clusters, 'Leaf'));
    }

    public function testZeroSeedsIsFatalAndReportsObservedMaxOutDegree(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'B'], ['B', 'C']],
            explicitSeeds: [],
            seedThreshold: 1000,
        );

        $result = $partitioner->partition($graph, $document);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('1', (string) $result->fatalError);
    }

    public function testUnknownExplicitSeedIsFatal(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'B'], ['B', 'C']],
            explicitSeeds: ['DoesNotExist'],
        );

        $result = $partitioner->partition($graph, $document);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('DoesNotExist', (string) $result->fatalError);
    }

    public function testExplicitSeedNamedMiscIsRejected(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'B'], ['B', 'C']],
            explicitSeeds: ['misc'],
        );

        $result = $partitioner->partition($graph, $document);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('reserved', (string) $result->fatalError);
    }

    public function testAutoSelectionNeverPicksMiscAsASeed(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['misc', 'W1'], ['misc', 'W2'], ['misc', 'W3']],
            explicitSeeds: [],
            seedThreshold: 3,
        );

        $result = $partitioner->partition($graph, $document);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('No seeds available', (string) $result->fatalError);
    }

    public function testDuplicateInvalidExplicitSeedIsReportedOnce(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'B'], ['B', 'C']],
            explicitSeeds: ['DoesNotExist', 'DoesNotExist'],
        );

        $result = $partitioner->partition($graph, $document);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('1 alias(es)', (string) $result->fatalError);
    }

    /**
     * Root's out-degree also clears the hub threshold, but an explicit
     * --seed names it, so it must be honored as a seed rather than silently
     * reclassified as a hub and rejected as "unknown" (same precedent as
     * --strategy=map's human assignment overriding hub detection).
     */
    public function testExplicitSeedOverridesHubDetection(): void
    {
        [$partitioner, $graph, $document] = $this->partitionerExcluding(
            [['Root', 'Z1'], ['Root', 'Z2'], ['Root', 'Z3']],
            excludedFromHubs: ['Root'],
            explicitSeeds: ['Root'],
            hubOutThreshold: 3,
        );

        $partition = $this->succeeded($partitioner->partition($graph, $document));

        $root = $this->findCluster($partition->clusters, 'Root');
        self::assertNotNull($root);
        self::assertContains('Z1', $root->members);
    }

    public function testIsDeterministicAcrossRuns(): void
    {
        [$partitioner, $graph, $document] = $this->partitioner(
            [['A', 'P1'], ['A', 'P2'], ['B', 'P3'], ['Y', 'P1'], ['Y', 'P2'], ['Y', 'P3']],
            explicitSeeds: ['A', 'B'],
        );

        $first = $this->serialize($this->succeeded($partitioner->partition($graph, $document)));
        $second = $this->serialize($this->succeeded($partitioner->partition($graph, $document)));

        self::assertSame($first, $second);
    }

    /**
     * @return array<string, list<string>>
     */
    private function serialize(Partition $partition): array
    {
        $map = [];
        foreach ($partition->clusters as $cluster) {
            $map[$cluster->name] = $cluster->members;
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
