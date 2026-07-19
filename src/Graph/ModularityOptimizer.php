<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Shared modularity-optimisation machinery ({@see LouvainClusterer} and
 * {@see LeidenClusterer} are both, at heart, "local moving + aggregation" —
 * Leiden's differentiator is the refinement pass it runs between the two,
 * which lives in {@see LeidenClusterer} itself since Louvain has no
 * equivalent step). Extracted here purely to avoid two hand-maintained
 * copies of identical graph bookkeeping.
 *
 * Deterministic throughout, matching plan §11: nodes/edges are always
 * visited in a fixed (alphabetical alias, then integer index) order, moves
 * require a strictly better integer score (no floating point, no rounding
 * ambiguity), and there is no randomness of any kind.
 */
final class ModularityOptimizer
{
    private const MAX_PASSES = 100;

    public function __construct(private readonly Graph $graph)
    {
    }

    /**
     * Induced undirected adjacency, unit weights, for the given alphabetically
     * sorted node list (index i in the returned structure corresponds to
     * $nodes[i]).
     *
     * @param list<string> $nodes alphabetically sorted
     *
     * @return array<int, array<int, int>>
     */
    public function buildAdjacency(array $nodes): array
    {
        $index = array_flip($nodes);
        $count = count($nodes);

        /** @var array<int, array<int, int>> $adjacency */
        $adjacency = array_fill(0, $count, []);
        foreach ($nodes as $i => $alias) {
            foreach ($this->graph->neighbours($alias) as $neighbour) {
                if (isset($index[$neighbour]) && $index[$neighbour] !== $i) {
                    $adjacency[$i][$index[$neighbour]] = 1;
                }
            }
        }

        return $adjacency;
    }

    /**
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     *
     * @return array<int, int>
     */
    public function degrees(array $adjacency, array $selfLoop): array
    {
        $degrees = [];
        foreach ($adjacency as $i => $neighbours) {
            $degrees[] = array_sum($neighbours) + 2 * $selfLoop[$i];
        }

        return $degrees;
    }

    /**
     * One round of local modularity optimisation to convergence: nodes move
     * between communities (may also move back out — unlike
     * {@see LeidenClusterer}'s merge-only refinement, this is the step that
     * can leave a community internally disconnected when a node that used to
     * bridge two parts of it moves away).
     *
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     * @param array<int, int>             $degree
     *
     * @return array<int, int> community label of each node
     */
    public function localMoving(array $adjacency, array $selfLoop, array $degree, int $twoM): array
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
    public function aggregate(array $adjacency, array $selfLoop, array $community): array
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
    public function toClusters(array $groups): array
    {
        $clusters = [];
        foreach ($groups as $members) {
            sort($members, SORT_STRING);
            $clusters[] = new Cluster($this->graph->nameByOutDegree($members), $members);
        }

        return Cluster::sortAll($clusters);
    }
}
