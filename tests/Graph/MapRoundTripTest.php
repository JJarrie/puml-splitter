<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Graph\MapEmitter;
use PumlSplitter\Graph\MapFileLoader;
use PumlSplitter\Graph\MapPartitioner;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;

/**
 * §9 round-trip: `auto` run → `--emit-map` → `--strategy=map` on the emitted
 * file → strictly identical partition (composition, cluster names, sizes,
 * edge accounting) — the workflow plan §6ter describes as the point of `map`.
 */
#[CoversNothing]
final class MapRoundTripTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/very-large.puml';
    private const MAX_SIZE = 25;
    private const MIN_SIZE = 3;

    private Document $document;

    protected function setUp(): void
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $this->document = (new Parser())->parse($content);
    }

    public function testAutoRunThenMapRunProduceTheIdenticalPartition(): void
    {
        $graph = Graph::fromDocument($this->document);

        $autoPartition = (new Partitioner(
            new HubDetector(8, 20, [], HubPolicy::Duplicate, []),
            new ConnectedComponents(),
            $this->autoClusterer($graph),
            new ClusterRefiner(self::MIN_SIZE, self::MAX_SIZE),
            self::MAX_SIZE,
        ))->partition($graph, $this->document);

        $mapJson = (new MapEmitter())->emit($autoPartition);

        $dir = sys_get_temp_dir() . '/puml-map-roundtrip-' . uniqid();
        mkdir($dir);
        $path = $dir . '/map.json';
        file_put_contents($path, $mapJson);

        try {
            $loadResult = (new MapFileLoader())->load($path);
            self::assertFalse($loadResult->isFatal());
            $map = $loadResult->map;
            self::assertNotNull($map);

            $mappedAliases = array_keys($map->aliasOwners());
            $mapPartitionResult = (new MapPartitioner(
                new HubDetector(8, 20, [], HubPolicy::Duplicate, [], $mappedAliases),
                new ConnectedComponents(),
                $this->autoClusterer($graph),
                new ClusterRefiner(self::MIN_SIZE, self::MAX_SIZE),
                self::MIN_SIZE,
                self::MAX_SIZE,
            ))->partition($graph, $this->document, $map);

            self::assertFalse($mapPartitionResult->isFatal());
            $mapPartition = $mapPartitionResult->partition;
            self::assertNotNull($mapPartition);

            $this->assertIdenticalPartitions($autoPartition, $mapPartition);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    private function assertIdenticalPartitions(Partition $expected, Partition $actual): void
    {
        self::assertSame($this->serialize($expected), $this->serialize($actual));
        self::assertSame($expected->internalEdges, $actual->internalEdges);
        self::assertSame($expected->interClusterEdges, $actual->interClusterEdges);
        self::assertSame($expected->hubEdges, $actual->hubEdges);
        self::assertSame($expected->totalEdges(), $actual->totalEdges());
    }

    /**
     * @return array<string, list<string>> cluster name => members, sorted by name
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

    private function autoClusterer(Graph $graph): AutoClusterer
    {
        $shortNames = [];
        foreach ($this->document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        return new AutoClusterer(new PrefixClusterer($shortNames, self::MAX_SIZE), new LouvainClusterer($graph), $graph, self::MAX_SIZE);
    }
}
