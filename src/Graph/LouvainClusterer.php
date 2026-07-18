<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Community detection by a naive, fully deterministic Louvain (plan §6.3) on the
 * undirected sub-graph induced by the given members.
 *
 * Two rounds of local modularity optimisation with a single aggregation layer
 * between them. Determinism is strict and total: nodes are indexed in alphabetical
 * alias order and always visited in that order; the move decision uses an integer
 * score (no floating point, so no rounding ambiguity), prefers staying in the
 * current community on a tie, and otherwise breaks ties by the lowest community
 * label; there is no randomness of any kind.
 */
final class LouvainClusterer implements Clusterer
{
    private const MAX_PASSES = 100;

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
        $index = array_flip($nodes);
        $count = count($nodes);

        // Induced undirected adjacency, unit weights (the source graph's
        // adjacency is already undirected and deduplicated).
        /** @var array<int, array<int, int>> $adjacency */
        $adjacency = array_fill(0, $count, []);
        foreach ($nodes as $i => $alias) {
            foreach ($this->graph->neighbours($alias) as $neighbour) {
                if (isset($index[$neighbour]) && $index[$neighbour] !== $i) {
                    $adjacency[$i][$index[$neighbour]] = 1;
                }
            }
        }

        /** @var array<int, int> $selfLoop */
        $selfLoop = array_fill(0, $count, 0);
        $degree = $this->degrees($adjacency, $selfLoop);
        $twoM = array_sum($degree);

        $level0 = $this->localMoving($adjacency, $selfLoop, $degree, $twoM);

        [$aggAdjacency, $aggSelfLoop, $label] = $this->aggregate($adjacency, $selfLoop, $level0);
        $aggDegree = $this->degrees($aggAdjacency, $aggSelfLoop);
        $level1 = $this->localMoving($aggAdjacency, $aggSelfLoop, $aggDegree, $twoM);

        /** @var array<int, list<string>> $groups */
        $groups = [];
        foreach ($nodes as $i => $alias) {
            $groups[$level1[$label[$level0[$i]]]][] = $alias;
        }

        return $this->toClusters($groups);
    }

    /**
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     *
     * @return array<int, int>
     */
    private function degrees(array $adjacency, array $selfLoop): array
    {
        $degrees = [];
        foreach ($adjacency as $i => $neighbours) {
            $degrees[] = array_sum($neighbours) + 2 * $selfLoop[$i];
        }

        return $degrees;
    }

    /**
     * One round of local modularity optimisation to convergence.
     *
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     * @param array<int, int>             $degree
     *
     * @return array<int, int> community label of each node
     */
    private function localMoving(array $adjacency, array $selfLoop, array $degree, int $twoM): array
    {
        $count = count($adjacency);
        /** @var array<int, int> $community */
        $community = range(0, $count - 1);
        $tot = $degree; // tot[c] = summed degree of community c (singletons initially)
        $nextLabel = $count; // fresh, never-reused labels for the isolated option

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $improved = false;

            for ($i = 0; $i < $count; $i++) {
                $current = $community[$i];
                $tot[$current] -= $degree[$i];

                /** @var array<int, int> $toCommunity weight from i to each neighbouring community */
                $toCommunity = [];
                foreach ($adjacency[$i] as $j => $weight) {
                    $toCommunity[$community[$j]] = ($toCommunity[$community[$j]] ?? 0) + $weight;
                }

                // Baseline: stay in the current community. Only a strictly better
                // score moves the node, which guarantees termination.
                $bestCommunity = $current;
                $bestScore = ($toCommunity[$current] ?? 0) * $twoM - $tot[$current] * $degree[$i];

                ksort($toCommunity);
                foreach ($toCommunity as $candidate => $weight) {
                    if ($candidate === $current) {
                        continue;
                    }
                    $score = $weight * $twoM - $tot[$candidate] * $degree[$i];
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestCommunity = $candidate;
                    }
                }

                // Isolated-singleton option (score 0): a node better off alone than
                // in the current community or any neighbour splits off. When i is
                // already alone the baseline score is already 0, so this never fires
                // then and cannot loop.
                if (0 > $bestScore) {
                    $bestCommunity = $nextLabel;
                    $nextLabel++;
                }

                $tot[$bestCommunity] = ($tot[$bestCommunity] ?? 0) + $degree[$i];
                $community[$i] = $bestCommunity;
                if ($bestCommunity !== $current) {
                    $improved = true;
                }
            }

            if (!$improved) {
                break;
            }
        }

        return $community;
    }

    /**
     * Collapses each community into a super-node, preserving total edge weight.
     *
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     * @param array<int, int>             $community
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, int>, 2: array<int, int>}
     *               aggregated adjacency, aggregated self-loops, and a map from
     *               original community label to compressed super-node id
     */
    private function aggregate(array $adjacency, array $selfLoop, array $community): array
    {
        $labels = array_values(array_unique($community));
        sort($labels);
        /** @var array<int, int> $label original community => super-node id */
        $label = array_flip($labels);
        $superCount = count($labels);

        /** @var array<int, array<int, int>> $aggAdjacency */
        $aggAdjacency = array_fill(0, $superCount, []);
        /** @var array<int, int> $aggSelfLoop */
        $aggSelfLoop = array_fill(0, $superCount, 0);

        foreach ($adjacency as $i => $neighbours) {
            $ci = $label[$community[$i]];
            $aggSelfLoop[$ci] += $selfLoop[$i];
            foreach ($neighbours as $j => $weight) {
                if ($j <= $i) {
                    continue; // count each undirected edge once
                }
                $cj = $label[$community[$j]];
                if ($ci === $cj) {
                    $aggSelfLoop[$ci] += $weight;
                } else {
                    $aggAdjacency[$ci][$cj] = ($aggAdjacency[$ci][$cj] ?? 0) + $weight;
                    $aggAdjacency[$cj][$ci] = ($aggAdjacency[$cj][$ci] ?? 0) + $weight;
                }
            }
        }

        return [$aggAdjacency, $aggSelfLoop, $label];
    }

    /**
     * @param array<int, list<string>> $groups
     *
     * @return list<Cluster>
     */
    private function toClusters(array $groups): array
    {
        $clusters = [];
        foreach ($groups as $members) {
            sort($members, SORT_STRING);
            $clusters[] = new Cluster($this->graph->nameByOutDegree($members), $members);
        }

        return Cluster::sortAll($clusters);
    }
}
