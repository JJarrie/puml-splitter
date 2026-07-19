<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\LeidenClusterer;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Tests\Support\Connectivity;
use PumlSplitter\Tests\Support\GraphFactory;

/**
 * §9 "Stratégies M7+" leiden coverage: the "trapped dumbbell" graph from
 * §6ter (two dense clusters joined by a thin bridge, embedded in enough
 * surrounding graph mass to trip Louvain's resolution limit and glue them
 * into one community) — Leiden must separate them, and every cluster it
 * produces, on this graph and on the real fixture, must be internally
 * connected. Written before the refinement implementation per CLAUDE.md's
 * TDD instruction for this milestone.
 */
#[CoversClass(LeidenClusterer::class)]
final class LeidenClustererTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/very-large.puml';

    /**
     * Two 3-cliques (L, R) joined by one bridge edge, plus an unrelated
     * background 10-clique in the same member set. The background inflates
     * total edge weight (twoM) enough that Louvain's modularity gain test
     * finds merging L+R favourable (the classic resolution-limit trap),
     * while it correctly keeps the much denser/larger background separate.
     * Confirmed empirically against the actual LouvainClusterer before
     * writing this assertion (not merely asserted from theory).
     */
    public function testTrappedDumbbellIsSeparatedByLeidenButGluedByLouvain(): void
    {
        [$edges, $members] = $this->trappedDumbbell();
        $graph = GraphFactory::fromEdges($edges);

        $louvainClusters = (new LouvainClusterer($graph))->cluster($members);
        self::assertSame(
            [6, 10],
            $this->sortedSizes($louvainClusters),
            'expected Louvain to glue the two 3-cliques into one 6-node community (the trap this test relies on)',
        );

        $leidenClusters = (new LeidenClusterer($graph))->cluster($members);
        self::assertSame(
            ['L1', 'L2', 'L3'],
            $this->ownerMembers($leidenClusters, 'L1'),
            'leiden should keep the L clique separate from R',
        );
        self::assertSame(
            ['R1', 'R2', 'R3'],
            $this->ownerMembers($leidenClusters, 'R1'),
            'leiden should keep the R clique separate from L',
        );

        self::assertSame([], Connectivity::disconnectedClusterNames($leidenClusters, $graph));
    }

    public function testEveryClusterIsInternallyConnectedOnTheTrappedDumbbell(): void
    {
        [$edges, $members] = $this->trappedDumbbell();
        $graph = GraphFactory::fromEdges($edges);

        $clusters = (new LeidenClusterer($graph))->cluster($members);

        self::assertNotEmpty($clusters);
        self::assertSame([], Connectivity::disconnectedClusterNames($clusters, $graph));
    }

    /**
     * The differentiating guarantee (plan §6ter), checked generally: every
     * cluster leiden produces on the real ~150-node fixture is internally
     * connected.
     */
    public function testEveryClusterIsInternallyConnectedOnTheRealFixture(): void
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $document = (new Parser())->parse($content);
        $graph = Graph::fromDocument($document);

        $clusters = (new LeidenClusterer($graph))->cluster($graph->nodes());

        self::assertNotEmpty($clusters);
        self::assertSame([], Connectivity::disconnectedClusterNames($clusters, $graph));
    }

    public function testTwoCliquesJoinedByOneEdge(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'a5', 'b1', 'b2', 'b3', 'b4', 'b5'];
        $edges = array_merge(
            $this->clique(['a1', 'a2', 'a3', 'a4', 'a5']),
            $this->clique(['b1', 'b2', 'b3', 'b4', 'b5']),
            [['a1', 'b1']],
        );

        $clusters = (new LeidenClusterer(GraphFactory::fromEdges($edges)))->cluster($members);

        self::assertSame(
            [['a1', 'a2', 'a3', 'a4', 'a5'], ['b1', 'b2', 'b3', 'b4', 'b5']],
            $this->communities($clusters),
        );
    }

    public function testCompleteGraphIsOneCommunity(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'a5'];
        $clusters = (new LeidenClusterer(GraphFactory::fromEdges($this->clique($members))))->cluster($members);

        self::assertSame([['a1', 'a2', 'a3', 'a4', 'a5']], $this->communities($clusters));
    }

    public function testEmptyGraphYieldsNoCommunities(): void
    {
        $clusters = (new LeidenClusterer(GraphFactory::fromEdges([])))->cluster([]);

        self::assertSame([], $clusters);
    }

    public function testIsolatedNodeIsItsOwnCommunity(): void
    {
        $clusters = (new LeidenClusterer(GraphFactory::fromEdges([], ['x'])))->cluster(['x']);

        self::assertSame([['x']], $this->communities($clusters));
    }

    public function testLinearChainIsAValidConnectedPartition(): void
    {
        $members = ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'];
        $edges = [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5'], ['n5', 'n6']];
        $graph = GraphFactory::fromEdges($edges);

        $clusters = (new LeidenClusterer($graph))->cluster($members);

        $seen = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                self::assertArrayNotHasKey($member, $seen, "duplicate node {$member}");
                $seen[$member] = true;
            }
        }
        $seenAliases = array_keys($seen);
        sort($seenAliases, SORT_STRING);
        self::assertSame($members, $seenAliases);
        self::assertSame([], Connectivity::disconnectedClusterNames($clusters, $graph));
    }

    public function testIsDeterministicAcrossRuns(): void
    {
        [$edges, $members] = $this->trappedDumbbell();
        $graph = GraphFactory::fromEdges($edges);
        $clusterer = new LeidenClusterer($graph);

        self::assertSame($this->serialize($clusterer->cluster($members)), $this->serialize($clusterer->cluster($members)));
    }

    /**
     * @return array{0: list<array{0: string, 1: string}>, 1: list<string>}
     */
    private function trappedDumbbell(): array
    {
        $left = ['L1', 'L2', 'L3'];
        $right = ['R1', 'R2', 'R3'];
        $background = array_map(static fn (int $i): string => "Bg{$i}", range(1, 10));

        $edges = array_merge(
            $this->clique($left),
            $this->clique($right),
            $this->clique($background),
            [['L1', 'R1']],
        );
        $members = array_merge($left, $right, $background);

        return [$edges, $members];
    }

    /**
     * All unordered pairs of the given nodes.
     *
     * @param list<string> $nodes
     *
     * @return list<array{0: string, 1: string}>
     */
    private function clique(array $nodes): array
    {
        $edges = [];
        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $edges[] = [$nodes[$i], $nodes[$j]];
            }
        }

        return $edges;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<int> cluster sizes, ascending
     */
    private function sortedSizes(array $clusters): array
    {
        $sizes = array_map(static fn (Cluster $c): int => $c->size(), $clusters);
        sort($sizes);

        return $sizes;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<string> members of whichever cluster contains $alias
     */
    private function ownerMembers(array $clusters, string $alias): array
    {
        foreach ($clusters as $cluster) {
            if ($cluster->contains($alias)) {
                return $cluster->members;
            }
        }

        return [];
    }

    /**
     * Sorted community member-lists, ordered by their smallest alias.
     *
     * @param list<Cluster> $clusters
     *
     * @return list<list<string>>
     */
    private function communities(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $cluster) {
            $out[] = $cluster->members;
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a[0] ?? '', $b[0] ?? ''));

        return $out;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<string> name + members in returned order
     */
    private function serialize(array $clusters): array
    {
        return array_map(static fn (Cluster $c): string => $c->name . ':' . implode(',', $c->members), $clusters);
    }
}
