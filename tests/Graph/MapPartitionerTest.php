<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Graph\MapFile;
use PumlSplitter\Graph\MapPartitionResult;
use PumlSplitter\Graph\MapPartitioner;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(MapPartitioner::class)]
final class MapPartitionerTest extends TestCase
{
    private function autoClusterer(Document $document, Graph $graph, int $maxSize = 25): AutoClusterer
    {
        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        return new AutoClusterer(new PrefixClusterer($shortNames, $maxSize), new LouvainClusterer($graph), $graph, $maxSize);
    }

    /**
     * @param list<string> $mappedAliases
     *
     * @return array{0: MapPartitioner, 1: Graph, 2: Document}
     */
    private function partitioner(array $mappedAliases = [], int $minSize = 3, int $maxSize = 25, ?Graph $graph = null, ?Document $document = null): array
    {
        $document ??= GraphFactory::document([['A', 'B'], ['B', 'C']]);
        $graph ??= Graph::fromDocument($document);

        $hubDetector = new HubDetector(8, 20, [], HubPolicy::Duplicate, [], $mappedAliases);
        $partitioner = new MapPartitioner(
            $hubDetector,
            new ConnectedComponents(),
            $this->autoClusterer($document, $graph, $maxSize),
            new ClusterRefiner($minSize, $maxSize),
            $minSize,
            $maxSize,
        );

        return [$partitioner, $graph, $document];
    }

    private function succeeded(MapPartitionResult $result): Partition
    {
        self::assertFalse($result->isFatal());
        self::assertNotNull($result->partition);

        return $result->partition;
    }

    public function testUnknownToGraphAliasWarnsButDoesNotBlockByDefault(): void
    {
        $document = GraphFactory::document([['A', 'B']]);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['x' => ['A', 'GhostAlias']], MapFile::FALLBACK_AUTO);

        [$partitioner] = $this->partitioner(['A', 'GhostAlias'], graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        $warningText = implode(' | ', $partition->warnings);
        self::assertStringContainsString('GhostAlias', $warningText);
    }

    public function testUnknownToGraphAliasIsFatalUnderFallbackErrorAndListsAll(): void
    {
        $document = GraphFactory::document([['A', 'B']]);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['x' => ['A', 'Ghost1', 'Ghost2']], MapFile::FALLBACK_ERROR);

        [$partitioner] = $this->partitioner(['A', 'Ghost1', 'Ghost2'], graph: $graph, document: $document);

        $result = $partitioner->partition($graph, $document, $map);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('Ghost1', (string) $result->fatalError);
        self::assertStringContainsString('Ghost2', (string) $result->fatalError);
    }

    public function testMappedClusterOfSizeOneIsExemptFromTheRefiner(): void
    {
        $document = GraphFactory::document([['A', 'B'], ['B', 'C']], ['Solo']);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['solo' => ['Solo']], MapFile::FALLBACK_AUTO);

        [$partitioner] = $this->partitioner(['Solo'], minSize: 3, maxSize: 25, graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        $solo = $this->findCluster($partition->clusters, 'solo');
        self::assertNotNull($solo);
        self::assertSame(['Solo'], $solo->members);

        $warningText = implode(' | ', $partition->warnings);
        self::assertStringContainsString('solo', $warningText);
        self::assertStringContainsString('outside [3, 25]', $warningText);
    }

    public function testMappedClusterAboveMaxSizeIsExemptFromTheRefiner(): void
    {
        $big = [];
        $edges = [];
        for ($i = 1; $i <= 40; $i++) {
            $big[] = "Big{$i}";
        }
        $document = GraphFactory::document($edges, $big);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['big' => $big], MapFile::FALLBACK_AUTO);

        [$partitioner] = $this->partitioner($big, minSize: 3, maxSize: 25, graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        $cluster = $this->findCluster($partition->clusters, 'big');
        self::assertNotNull($cluster);
        self::assertCount(40, $cluster->members);

        $warningText = implode(' | ', $partition->warnings);
        self::assertStringContainsString('big', $warningText);
        self::assertStringContainsString('outside [3, 25]', $warningText);
    }

    public function testFallbackAutoClustersUnmappedNodesThroughTheNormalPipeline(): void
    {
        // A/B/C mapped; D/E/F unmapped but connected to each other.
        $document = GraphFactory::document([['A', 'B'], ['B', 'C'], ['D', 'E'], ['E', 'F']]);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['x' => ['A', 'B', 'C']], MapFile::FALLBACK_AUTO);

        [$partitioner] = $this->partitioner(['A', 'B', 'C'], minSize: 1, maxSize: 25, graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        $allMembers = [];
        foreach ($partition->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $allMembers[] = $member;
            }
        }
        sort($allMembers);
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $allMembers);
    }

    public function testFallbackMiscGroupsUnmappedNodesIntoOneCluster(): void
    {
        $document = GraphFactory::document([['A', 'B'], ['D', 'E']]);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['x' => ['A', 'B']], MapFile::FALLBACK_MISC);

        [$partitioner] = $this->partitioner(['A', 'B'], minSize: 1, maxSize: 25, graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        $misc = $this->findCluster($partition->clusters, ClusterRefiner::MISC);
        self::assertNotNull($misc);
        self::assertSame(['D', 'E'], $misc->members);
    }

    public function testFallbackErrorIsFatalWhenGraphNodesAreUnmappedAndListsAllAbsent(): void
    {
        $document = GraphFactory::document([['A', 'B'], ['D', 'E']]);
        $graph = Graph::fromDocument($document);
        $map = new MapFile(['x' => ['A', 'B']], MapFile::FALLBACK_ERROR);

        [$partitioner] = $this->partitioner(['A', 'B'], minSize: 1, maxSize: 25, graph: $graph, document: $document);

        $result = $partitioner->partition($graph, $document, $map);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('D', (string) $result->fatalError);
        self::assertStringContainsString('E', (string) $result->fatalError);
    }

    public function testMappedAliasIsNeverDetectedAsAHubEvenIfItCrossesTheThreshold(): void
    {
        // Hub1 has in-degree 8 (>= default threshold) but is mapped.
        $edges = [];
        for ($i = 1; $i <= 8; $i++) {
            $edges[] = ["Caller{$i}", 'Hub1'];
        }
        $document = GraphFactory::document($edges);
        $graph = Graph::fromDocument($document);

        $callers = [];
        for ($i = 1; $i <= 8; $i++) {
            $callers[] = "Caller{$i}";
        }
        $mapped = [...$callers, 'Hub1'];
        $map = new MapFile(['x' => $mapped], MapFile::FALLBACK_AUTO);

        [$partitioner] = $this->partitioner($mapped, minSize: 1, maxSize: 25, graph: $graph, document: $document);

        $partition = $this->succeeded($partitioner->partition($graph, $document, $map));

        self::assertSame([], $partition->hubs);
        $cluster = $this->findCluster($partition->clusters, 'x');
        self::assertNotNull($cluster);
        self::assertContains('Hub1', $cluster->members);
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
}
