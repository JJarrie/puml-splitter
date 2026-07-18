<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Clusterer;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(ClusterRefiner::class)]
final class ClusterRefinerTest extends TestCase
{
    public function testSplitsAnOversizedClusterViaTheStrategy(): void
    {
        $graph = GraphFactory::fromEdges([], ['n1', 'n2', 'n3', 'n4', 'n5', 'n6']);
        $refiner = new ClusterRefiner(minSize: 1, maxSize: 5);

        $result = $refiner->refine(
            [new Cluster('big', ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'])],
            $graph,
            $this->halfSplitter(),
        );

        self::assertSame(['partA', 'partB'], array_map(static fn (Cluster $c): string => $c->name, $result));
        self::assertSame(['n1', 'n2', 'n3'], $result[0]->members);
        self::assertSame(['n4', 'n5', 'n6'], $result[1]->members);
    }

    public function testLeavesUnsplittableClusterIntact(): void
    {
        $graph = GraphFactory::fromEdges([], ['n1', 'n2', 'n3', 'n4', 'n5', 'n6']);
        $refiner = new ClusterRefiner(minSize: 1, maxSize: 5);

        // A strategy that cannot split (returns the same single cluster).
        $result = $refiner->refine(
            [new Cluster('big', ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'])],
            $graph,
            $this->noSplitter(),
        );

        self::assertCount(1, $result);
        self::assertSame(6, $result[0]->size());
    }

    public function testRejectsSplitThatDropsMembers(): void
    {
        $graph = GraphFactory::fromEdges([], ['n1', 'n2', 'n3', 'n4', 'n5', 'n6']);
        $refiner = new ClusterRefiner(minSize: 1, maxSize: 5);

        // A strategy whose parts omit n6 must be rejected: the cluster stays whole.
        $lossy = new class implements Clusterer {
            public function cluster(array $members): array
            {
                return [new Cluster('a', ['n1', 'n2', 'n3']), new Cluster('b', ['n4', 'n5'])];
            }
        };

        $result = $refiner->refine(
            [new Cluster('big', ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'])],
            $graph,
            $lossy,
        );

        self::assertCount(1, $result);
        self::assertSame(6, $result[0]->size());
    }

    public function testMergesUndersizedClusterIntoMostConnectedNeighbour(): void
    {
        $graph = GraphFactory::fromEdges([['a', 'b1'], ['b1', 'b2'], ['b2', 'b3']]);
        $refiner = new ClusterRefiner(minSize: 3, maxSize: 25);

        $result = $refiner->refine(
            [new Cluster('B', ['b1', 'b2', 'b3']), new Cluster('S', ['a'])],
            $graph,
            $this->noSplitter(),
        );

        self::assertCount(1, $result);
        self::assertSame('B', $result[0]->name);
        self::assertSame(['a', 'b1', 'b2', 'b3'], $result[0]->members);
    }

    public function testMergesDisconnectedUndersizedClusterIntoMisc(): void
    {
        $graph = GraphFactory::fromEdges([['b1', 'b2'], ['b2', 'b3']], ['x']);
        $refiner = new ClusterRefiner(minSize: 3, maxSize: 25);

        $result = $refiner->refine(
            [new Cluster('B', ['b1', 'b2', 'b3']), new Cluster('I', ['x'])],
            $graph,
            $this->noSplitter(),
        );

        self::assertSame(['B', 'misc'], array_map(static fn (Cluster $c): string => $c->name, $result));
        self::assertSame(['x'], $result[1]->members);
    }

    private function halfSplitter(): Clusterer
    {
        return new class implements Clusterer {
            public function cluster(array $members): array
            {
                sort($members, SORT_STRING);
                $half = intdiv(count($members), 2);

                return [
                    new Cluster('partA', array_slice($members, 0, $half)),
                    new Cluster('partB', array_slice($members, $half)),
                ];
            }
        };
    }

    private function noSplitter(): Clusterer
    {
        return new class implements Clusterer {
            public function cluster(array $members): array
            {
                return [new Cluster('whole', $members)];
            }
        };
    }
}
