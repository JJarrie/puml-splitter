<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Community detection by a naive, fully deterministic Louvain (plan §6.3) on the
 * undirected sub-graph induced by the given members.
 *
 * Two rounds of local modularity optimisation ({@see ModularityOptimizer}) with a
 * single aggregation layer between them. Determinism is strict and total: nodes
 * are indexed in alphabetical alias order and always visited in that order; the
 * move decision uses an integer score (no floating point, so no rounding
 * ambiguity), prefers staying in the current community on a tie, and otherwise
 * breaks ties by the lowest community label; there is no randomness of any kind.
 *
 * Known limitation (plan §6ter, fixed by {@see LeidenClusterer}): because local
 * moving lets a node leave a community it previously helped connect, the
 * resulting communities are not guaranteed to be internally connected.
 */
final class LouvainClusterer implements Clusterer
{
    public function __construct(private readonly Graph $graph)
    {
    }

    public function cluster(array $members): array
    {
        if ($members === []) {
            return [];
        }

        $nodes = $members;
        sort($nodes, SORT_STRING);
        $optimizer = new ModularityOptimizer($this->graph);

        $adjacency = $optimizer->buildAdjacency($nodes);
        $selfLoop = array_fill(0, count($nodes), 0);
        $degree = $optimizer->degrees($adjacency, $selfLoop);
        $twoM = array_sum($degree);

        $level0 = $optimizer->localMoving($adjacency, $selfLoop, $degree, $twoM);

        [$aggAdjacency, $aggSelfLoop, $label] = $optimizer->aggregate($adjacency, $selfLoop, $level0);
        $aggDegree = $optimizer->degrees($aggAdjacency, $aggSelfLoop);
        $level1 = $optimizer->localMoving($aggAdjacency, $aggSelfLoop, $aggDegree, $twoM);

        /** @var array<int, list<string>> $groups */
        $groups = [];
        foreach ($nodes as $i => $alias) {
            $groups[$level1[$label[$level0[$i]]]][] = $alias;
        }

        return $optimizer->toClusters($groups);
    }
}
