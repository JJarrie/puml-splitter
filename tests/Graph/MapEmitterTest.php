<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Graph\MapEmitter;
use PumlSplitter\Graph\Partition;

#[CoversClass(MapEmitter::class)]
final class MapEmitterTest extends TestCase
{
    public function testSortsClustersByNameAndAliasesAlphabetically(): void
    {
        $partition = new Partition(
            clusters: [new Cluster('beta', ['B2', 'B1']), new Cluster('alpha', ['A1'])],
            hubs: [],
            internalEdges: 0,
            interClusterEdges: 0,
            hubEdges: 0,
            internalByCluster: [0, 0],
            externalByCluster: [0, 0],
        );

        $json = (new MapEmitter())->emit($partition);
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertIsArray($data['clusters']);

        self::assertSame(['alpha', 'beta'], array_keys($data['clusters']));
        self::assertSame(['B1', 'B2'], $data['clusters']['beta']);
        self::assertSame('auto', $data['fallback']);
    }

    public function testExcludesHubsFromTheEmittedMap(): void
    {
        // Partition.clusters never contains hub members in the first place —
        // this documents that invariant rather than filtering anything here.
        $hub = new Hub('H', 10, 0, HubReason::In, false, HubPolicy::Duplicate);
        $partition = new Partition(
            clusters: [new Cluster('alpha', ['A1'])],
            hubs: [$hub],
            internalEdges: 0,
            interClusterEdges: 0,
            hubEdges: 0,
            internalByCluster: [0],
            externalByCluster: [0],
        );

        $json = (new MapEmitter())->emit($partition);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame(['alpha' => ['A1']], $data['clusters']);
    }

    public function testEmissionIsDeterministic(): void
    {
        $partition = new Partition(
            clusters: [new Cluster('beta', ['B1']), new Cluster('alpha', ['A1'])],
            hubs: [],
            internalEdges: 0,
            interClusterEdges: 0,
            hubEdges: 0,
            internalByCluster: [0, 0],
            externalByCluster: [0, 0],
        );

        $emitter = new MapEmitter();
        self::assertSame($emitter->emit($partition), $emitter->emit($partition));
    }

    public function testEmptyPartitionEmitsAnEmptyClustersObject(): void
    {
        $json = (new MapEmitter())->emit(new Partition([], [], 0, 0, 0, [], []));
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame([], $data['clusters']);
    }

    /**
     * Two clusters CAN legitimately share a name (e.g. a mapped cluster
     * literally named "misc" plus a fallback=misc cluster using that same
     * name) — Partition::$clusters is a list, not keyed by name. Both must
     * survive emission, not silently collide on the same JSON key.
     */
    public function testTwoClustersSharingANameBothSurviveEmission(): void
    {
        $partition = new Partition(
            clusters: [new Cluster('misc', ['D', 'E']), new Cluster('misc', ['A', 'B'])],
            hubs: [],
            internalEdges: 0,
            interClusterEdges: 0,
            hubEdges: 0,
            internalByCluster: [0, 0],
            externalByCluster: [0, 0],
        );

        $json = (new MapEmitter())->emit($partition);
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertIsArray($data['clusters']);

        $allMembers = [];
        foreach ($data['clusters'] as $members) {
            self::assertIsArray($members);
            foreach ($members as $member) {
                $allMembers[] = $member;
            }
        }
        sort($allMembers);
        self::assertSame(['A', 'B', 'D', 'E'], $allMembers);
        self::assertCount(2, $data['clusters']);
    }
}
