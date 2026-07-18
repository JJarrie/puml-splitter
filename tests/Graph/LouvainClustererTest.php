<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(LouvainClusterer::class)]
final class LouvainClustererTest extends TestCase
{
    public function testTwoCliquesJoinedByOneEdge(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'a5', 'b1', 'b2', 'b3', 'b4', 'b5'];
        $edges = array_merge(
            $this->clique(['a1', 'a2', 'a3', 'a4', 'a5']),
            $this->clique(['b1', 'b2', 'b3', 'b4', 'b5']),
            [['a1', 'b1']],
        );

        $clusters = (new LouvainClusterer(GraphFactory::fromEdges($edges)))->cluster($members);

        self::assertSame(
            [['a1', 'a2', 'a3', 'a4', 'a5'], ['b1', 'b2', 'b3', 'b4', 'b5']],
            $this->communities($clusters),
        );
    }

    public function testThreeCliquesInATriangle(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'b1', 'b2', 'b3', 'b4', 'c1', 'c2', 'c3', 'c4'];
        $edges = array_merge(
            $this->clique(['a1', 'a2', 'a3', 'a4']),
            $this->clique(['b1', 'b2', 'b3', 'b4']),
            $this->clique(['c1', 'c2', 'c3', 'c4']),
            [['a1', 'b1'], ['b1', 'c1'], ['a1', 'c1']],
        );

        $clusters = (new LouvainClusterer(GraphFactory::fromEdges($edges)))->cluster($members);

        self::assertSame(
            [
                ['a1', 'a2', 'a3', 'a4'],
                ['b1', 'b2', 'b3', 'b4'],
                ['c1', 'c2', 'c3', 'c4'],
            ],
            $this->communities($clusters),
        );
    }

    public function testCompleteGraphIsOneCommunity(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'a5'];
        $clusters = (new LouvainClusterer(GraphFactory::fromEdges($this->clique($members))))->cluster($members);

        self::assertSame([['a1', 'a2', 'a3', 'a4', 'a5']], $this->communities($clusters));
    }

    public function testEmptyGraphYieldsNoCommunities(): void
    {
        $clusters = (new LouvainClusterer(GraphFactory::fromEdges([])))->cluster([]);

        self::assertSame([], $clusters);
    }

    public function testIsolatedNodeIsItsOwnCommunity(): void
    {
        $clusters = (new LouvainClusterer(GraphFactory::fromEdges([], ['x'])))->cluster(['x']);

        self::assertSame([['x']], $this->communities($clusters));
    }

    public function testLinearChainIsAValidPartition(): void
    {
        $members = ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'];
        $edges = [['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n5'], ['n5', 'n6']];

        $clusters = (new LouvainClusterer(GraphFactory::fromEdges($edges)))->cluster($members);

        // No exact structure asserted (implementation-dependent), only that every
        // node appears in exactly one community.
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
    }

    public function testIsDeterministicAcrossRuns(): void
    {
        $members = ['a1', 'a2', 'a3', 'a4', 'a5', 'b1', 'b2', 'b3', 'b4', 'b5'];
        $edges = array_merge(
            $this->clique(['a1', 'a2', 'a3', 'a4', 'a5']),
            $this->clique(['b1', 'b2', 'b3', 'b4', 'b5']),
            [['a1', 'b1']],
        );
        $clusterer = new LouvainClusterer(GraphFactory::fromEdges($edges));

        self::assertSame($this->serialize($clusterer->cluster($members)), $this->serialize($clusterer->cluster($members)));
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
