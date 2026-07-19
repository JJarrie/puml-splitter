<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Leiden clustering (plan §6ter, M9, Traag et al. 2019): Louvain's local
 * moving + aggregation ({@see ModularityOptimizer}), with a refinement pass
 * inserted before each aggregation. That refinement is what fixes Louvain's
 * documented defect — internally disconnected communities. It happens
 * because Louvain's local moving lets a node leave a community it used to be
 * the sole bridge within, orphaning the rest under the same label without
 * ever re-checking whether they're still connected to each other. Refinement
 * closes that gap: starting from singletons *within each local-moving
 * community*, it only ever MERGES two pieces, and only across a real edge
 * between them — merge-only, never move-and-leave. By induction that makes
 * every refined community a union of pieces joined by actual edges, i.e.
 * connected; and because refinement runs again on the aggregate graph before
 * *its* aggregation too, the guarantee survives every level, not just the
 * first.
 *
 * The canonical algorithm picks a merge target randomly among qualifying
 * candidates (weighted by quality gain), which the plan's determinism
 * requirement (§11: no randomness of any kind) rules out here — this
 * implementation always merges into the candidate with the strictly best
 * modularity gain, alphabetical-index tie-break, and never merges on a
 * non-positive gain. This is a deliberate, disclosed deviation from the
 * paper's stochastic refinement, not an oversight: it trades a small amount
 * of exploration (the randomised version can occasionally accept a slightly
 * worse merge and dig out of a local optimum two steps later) for total
 * reproducibility, which this project requires unconditionally.
 */
final class LeidenClusterer implements Clusterer
{
    private const MAX_REFINE_PASSES = 100;

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

        $coarse0 = $optimizer->localMoving($adjacency, $selfLoop, $degree, $twoM);
        $refined0 = $this->refine($coarse0, $adjacency, $selfLoop, $optimizer);

        [$aggAdjacency, $aggSelfLoop, $label0] = $optimizer->aggregate($adjacency, $selfLoop, $refined0);
        $aggDegree = $optimizer->degrees($aggAdjacency, $aggSelfLoop);

        $coarse1 = $optimizer->localMoving($aggAdjacency, $aggSelfLoop, $aggDegree, $twoM);
        $refined1 = $this->refine($coarse1, $aggAdjacency, $aggSelfLoop, $optimizer);

        /** @var array<int, list<string>> $groups */
        $groups = [];
        foreach ($nodes as $i => $alias) {
            $groups[$refined1[$label0[$refined0[$i]]]][] = $alias;
        }

        return $optimizer->toClusters($groups);
    }

    /**
     * Refines a local-moving partition into a (possibly finer) one where
     * every community is guaranteed connected: each local-moving community is
     * processed independently and split, via merge-only agglomeration, into
     * pieces that are unions of directly-connected nodes.
     *
     * @param array<int, int>             $coarse    community label per node index
     * @param array<int, array<int, int>> $adjacency
     * @param array<int, int>             $selfLoop
     *
     * @return array<int, int> refined community label per node index (a node
     *                          index that was never merged keeps its own index
     *                          as its label)
     */
    private function refine(array $coarse, array $adjacency, array $selfLoop, ModularityOptimizer $optimizer): array
    {
        $refined = array_keys($coarse);

        /** @var array<int, list<int>> $byCommunity */
        $byCommunity = [];
        foreach ($coarse as $i => $c) {
            $byCommunity[$c][] = $i;
        }
        $labels = array_keys($byCommunity);
        sort($labels);

        foreach ($labels as $label) {
            $group = $byCommunity[$label];
            sort($group);
            $this->refineGroup($group, $adjacency, $selfLoop, $optimizer, $refined);
        }

        return $refined;
    }

    /**
     * Merge-only agglomeration restricted to one local-moving community: two
     * (possibly already-merged) pieces are unified only when they are joined
     * by a real edge AND doing so strictly improves modularity, evaluated
     * against totals local to this community's own induced sub-graph. Once
     * merged, a piece never splits again — that monotonicity is what
     * guarantees connectivity, unlike {@see ModularityOptimizer::localMoving()}.
     *
     * @param list<int>                    $group     node indices, ascending (alphabetical)
     * @param array<int, array<int, int>>  $adjacency
     * @param array<int, int>              $selfLoop
     * @param array<int, int>              $refined   mutated in place for indices in $group
     */
    private function refineGroup(array $group, array $adjacency, array $selfLoop, ModularityOptimizer $optimizer, array &$refined): void
    {
        $inGroup = array_fill_keys($group, true);

        /** @var array<int, array<int, int>> $restrictedAdjacency $adjacency, keyed like it, but with every edge leaving $group dropped */
        $restrictedAdjacency = [];
        /** @var list<array{0: int, 1: int, 2: int}> $edges (u, v, weight), u < v, both in $group */
        $edges = [];
        foreach ($group as $i) {
            $restrictedAdjacency[$i] = array_intersect_key($adjacency[$i], $inGroup);
            foreach ($restrictedAdjacency[$i] as $j => $weight) {
                if ($j > $i) {
                    $edges[] = [$i, $j, $weight];
                }
            }
        }

        if ($edges === []) {
            return; // no internal edges: singletons are already correct (and connected)
        }

        // Same degree formula as ModularityOptimizer::localMoving() uses
        // elsewhere (2*selfLoop + sum of incident weights), reused rather than
        // re-derived, restricted to this community by feeding it the adjacency
        // with every out-of-group edge already stripped.
        $degree = array_combine($group, $optimizer->degrees($restrictedAdjacency, $selfLoop));
        $twoM = array_sum($degree);

        usort($edges, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $parent = array_combine($group, $group);
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        $tot = $degree; // per-root aggregate degree, keyed by current root id

        for ($pass = 0; $pass < self::MAX_REFINE_PASSES; $pass++) {
            $changed = false;

            // Symmetric edge weight between each pair of current (pass-start)
            // roots, both directions stored so a root's candidates are a
            // direct lookup, never a second scan over every other root.
            /** @var array<int, array<int, int>> $between */
            $between = [];
            foreach ($edges as [$u, $v, $weight]) {
                $ru = $find($u);
                $rv = $find($v);
                if ($ru === $rv) {
                    continue;
                }
                $between[$ru][$rv] = ($between[$ru][$rv] ?? 0) + $weight;
                $between[$rv][$ru] = ($between[$rv][$ru] ?? 0) + $weight;
            }

            foreach ($group as $i) {
                if ($find($i) !== $i) {
                    continue; // not a current root, already processed via its root
                }
                $ru = $i;

                // Re-resolve every neighbour to its CURRENT root: an earlier
                // merge within this very pass can leave $between keyed by a
                // root that has since been absorbed elsewhere, which would
                // otherwise score this candidate against a stale, no-longer-
                // updated $tot instead of its true (now merged) total.
                /** @var array<int, int> $candidates current root => edge weight */
                $candidates = [];
                foreach ($between[$ru] ?? [] as $other => $weight) {
                    $root = $find($other);
                    if ($root === $ru) {
                        continue;
                    }
                    $candidates[$root] = ($candidates[$root] ?? 0) + $weight;
                }
                if ($candidates === []) {
                    continue;
                }
                ksort($candidates);

                $bestRoot = null;
                $bestScore = 0;
                foreach ($candidates as $candidateRoot => $weight) {
                    $score = $weight * $twoM - $tot[$ru] * $tot[$candidateRoot];
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestRoot = $candidateRoot;
                    }
                }
                if ($bestRoot === null) {
                    continue;
                }

                $survivor = min($ru, $bestRoot);
                $absorbed = max($ru, $bestRoot);
                $parent[$absorbed] = $survivor;
                $tot[$survivor] = $tot[$ru] + $tot[$bestRoot];
                $changed = true;
            }

            if (!$changed) {
                break;
            }
        }

        foreach ($group as $i) {
            $refined[$i] = $find($i);
        }
    }
}
