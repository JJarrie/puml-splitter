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

    /**
     * Mirrors the real-world case that exposed the bug: many isolated
     * Command_ and Controller_ prefixed aliases with no edges between them
     * (so merge() alone dumps them all into one oversized `misc`), but which
     * a name-aware strategy (prefix-like here) can still meaningfully split
     * even without graph edges to guide it.
     */
    public function testReSplitsOversizedMiscProducedDuringMergeUsingProvidedStrategy(): void
    {
        $members = [];
        for ($i = 1; $i <= 15; $i++) {
            $members[] = "Command_Foo{$i}";
            $members[] = "Controller_Bar{$i}";
        }
        $graph = GraphFactory::fromEdges([], $members);
        $refiner = new ClusterRefiner(minSize: 3, maxSize: 20);

        $singletons = array_map(static fn (string $m): Cluster => new Cluster($m, [$m]), $members);
        $result = $refiner->refine($singletons, $graph, $this->prefixSplitter());

        foreach ($result as $cluster) {
            self::assertLessThanOrEqual(20, $cluster->size(), "cluster \"{$cluster->name}\" exceeds max-size");
        }

        $resultMembers = [];
        foreach ($result as $cluster) {
            foreach ($cluster->members as $member) {
                self::assertArrayNotHasKey($member, $resultMembers, "duplicate member {$member}");
                $resultMembers[$member] = true;
            }
        }
        sort($members, SORT_STRING);
        $resultAliases = array_keys($resultMembers);
        sort($resultAliases, SORT_STRING);
        self::assertSame($members, $resultAliases);

        self::assertNull($this->findCluster($result, ClusterRefiner::MISC));
    }

    /**
     * A strategy that genuinely cannot split (noSplitter()) must not trap
     * refine() in an infinite loop: it terminates within the bounded round
     * count and returns the oversized misc as-is, rather than hanging or
     * corrupting membership.
     */
    public function testStopsAfterBoundedRoundsWhenMiscCannotBeSplitFurther(): void
    {
        $members = [];
        for ($i = 1; $i <= 15; $i++) {
            $members[] = "Command_Foo{$i}";
            $members[] = "Controller_Bar{$i}";
        }
        $graph = GraphFactory::fromEdges([], $members);
        $refiner = new ClusterRefiner(minSize: 3, maxSize: 20);

        $singletons = array_map(static fn (string $m): Cluster => new Cluster($m, [$m]), $members);
        $result = $refiner->refine($singletons, $graph, $this->noSplitter());

        self::assertCount(1, $result);
        self::assertSame(ClusterRefiner::MISC, $result[0]->name);
        sort($members, SORT_STRING);
        self::assertSame($members, $result[0]->members);
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

    private function prefixSplitter(): Clusterer
    {
        return new class implements Clusterer {
            public function cluster(array $members): array
            {
                $groups = [];
                foreach ($members as $member) {
                    $groups[explode('_', $member)[0]][] = $member;
                }
                ksort($groups, SORT_STRING);

                $result = [];
                foreach ($groups as $prefix => $groupMembers) {
                    $result[] = new Cluster($prefix, $groupMembers);
                }

                return $result;
            }
        };
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
