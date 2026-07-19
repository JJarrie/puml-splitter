<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Support;

use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;

/**
 * Reusable "is this cluster's induced sub-graph connected" check (plan §6ter,
 * M9): the property Leiden's refinement guarantees and Louvain does not.
 * Delegates to {@see ConnectedComponents} (excluding every node outside the
 * cluster) rather than hand-rolling a second graph walk.
 */
final class Connectivity
{
    public static function isConnected(Cluster $cluster, Graph $graph): bool
    {
        if ($cluster->size() <= 1) {
            return true;
        }

        $memberSet = array_fill_keys($cluster->members, true);
        $outsideMembers = array_values(array_filter(
            $graph->nodes(),
            static fn (string $alias): bool => !isset($memberSet[$alias]),
        ));

        $components = (new ConnectedComponents())->compute($graph, $outsideMembers);

        return count($components) === 1;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<string> names of clusters that are NOT internally connected
     */
    public static function disconnectedClusterNames(array $clusters, Graph $graph): array
    {
        $names = [];
        foreach ($clusters as $cluster) {
            if (!self::isConnected($cluster, $graph)) {
                $names[] = $cluster->name;
            }
        }

        return $names;
    }
}
