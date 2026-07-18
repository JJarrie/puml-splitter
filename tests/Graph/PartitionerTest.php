<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;

#[CoversClass(Partitioner::class)]
#[CoversClass(Partition::class)]
final class PartitionerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/very-large.puml';

    private function partition(): Partition
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $document = (new Parser())->parse($content);

        return $this->partitionerWithDefaults($document)->partition(Graph::fromDocument($document), $document);
    }

    private function partitionerWithDefaults(Document $document): Partitioner
    {
        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        return new Partitioner(
            hubDetector: new HubDetector(8, 20, [], HubPolicy::Duplicate, []),
            components: new ConnectedComponents(),
            strategy: new PrefixClusterer($shortNames, 25),
            refiner: new ClusterRefiner(3, 25),
            maxSize: 25,
        );
    }

    public function testDetectsExactlyThreeHubsWithExpectedShape(): void
    {
        // Identified by degree, not by (token-anonymized) alias: two in-degree
        // hubs (11 and 12) and one out-only hub (68) with the separate default.
        $partition = $this->partition();

        self::assertCount(3, $partition->hubs);

        $outHubs = array_values(array_filter($partition->hubs, static fn (Hub $h): bool => $h->reason === HubReason::Out));
        $inHubs = array_values(array_filter($partition->hubs, static fn (Hub $h): bool => $h->reason === HubReason::In));

        self::assertCount(1, $outHubs);
        self::assertSame(68, $outHubs[0]->outDegree);
        self::assertSame(HubPolicy::Separate, $outHubs[0]->policy);

        $inDegrees = array_map(static fn (Hub $h): int => $h->inDegree, $inHubs);
        sort($inDegrees);
        self::assertSame([11, 12], $inDegrees);
    }

    public function testEdgeAccountingCoversEveryInputRelation(): void
    {
        $partition = $this->partition();

        // No lost edges: internal + inter-cluster + hub == total input relations.
        self::assertSame(290, $partition->totalEdges());
    }

    public function testPrefixProducesANonDegenerateSplit(): void
    {
        $partition = $this->partition();

        // Token anonymization preserves shared prefixes, so the giant component
        // is split into many clusters, all within the size bound.
        self::assertSame([], $partition->oversizedClusters(25));
        self::assertGreaterThan(10, count($partition->clusters));
        self::assertGreaterThan(0, $partition->interClusterEdges);

        // Every non-hub node appears in exactly one cluster (156 classes - 3 hubs).
        $seen = [];
        foreach ($partition->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                self::assertArrayNotHasKey($member, $seen, "duplicate node {$member}");
                $seen[$member] = true;
            }
        }
        self::assertCount(153, $seen);
    }
}
