<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Brings clusters within the [min-size, max-size] band (plan §6.4):
 *  - clusters over max-size are re-split via the given {@see Clusterer}, but only
 *    if that makes progress — an unsplittable component is left intact (never
 *    force-split);
 *  - clusters under min-size are merged into their most-connected neighbouring
 *    cluster, or into a terminal `misc` cluster when they have no such neighbour.
 *
 * A single split→merge pass isn't enough on its own: `misc` (and any cluster
 * that absorbs an undersized neighbour via `mostConnected`) is only ever
 * produced/grown *during* merge, strictly after split already ran, so nothing
 * re-checks it against max-size afterward. `refine()` therefore repeats the
 * split→merge cycle — generically, with no special case for the name `misc`
 * — until no cluster exceeds max-size, the cluster set stops changing between
 * rounds, or {@see MAX_REFINE_ROUNDS} is reached (plan §6.4 amendment).
 *
 * Deterministic throughout: candidates and tie-breaks are ordered by alias.
 */
final class ClusterRefiner
{
    public const MISC = 'misc';

    private const MAX_REFINE_ROUNDS = 5;

    public function __construct(
        private readonly int $minSize,
        private readonly int $maxSize,
    ) {
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<Cluster>
     */
    public function refine(array $clusters, Graph $graph, Clusterer $splitter): array
    {
        $previousSignature = null;
        // Member-set signatures already confirmed unsplittable by $splitter
        // this call — memoized across rounds so a component stuck oversized
        // (e.g. locked to a hub) doesn't get the strategy re-run on the exact
        // same members every round; a differently-composed cluster (a fresh
        // key) always gets a fresh attempt.
        /** @var array<string, true> $unsplittable */
        $unsplittable = [];

        if ($splitter instanceof AutoClusterer) {
            $splitter->resetDecisions();
        }

        for ($round = 0; $round < self::MAX_REFINE_ROUNDS; $round++) {
            $clusters = $this->split($clusters, $splitter, $unsplittable);
            $clusters = $this->merge($clusters, $graph);

            if (!$this->anyOversized($clusters)) {
                break;
            }

            // No progress since the last round (the strategy genuinely cannot
            // split what's left, as splitRecursively() already determined) —
            // further rounds would be pure no-ops. Accept the current state;
            // the caller's existing oversized-cluster warning still fires.
            $signature = $this->signature($clusters);
            if ($signature === $previousSignature) {
                break;
            }
            $previousSignature = $signature;

            // A merge earlier this round can reshape which components exist;
            // only decisions from the round whose split() output survives
            // into the final result should be reported (plan §6.4 amendment)
            // — reset before the next round so intermediate, superseded
            // decisions from THIS round don't linger in the dry-run report.
            if ($splitter instanceof AutoClusterer) {
                $splitter->resetDecisions();
            }
        }

        return Cluster::sortAll($clusters);
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function anyOversized(array $clusters): bool
    {
        foreach ($clusters as $cluster) {
            if ($cluster->size() > $this->maxSize) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical name:sorted-members signature of the whole cluster set, order
     * -independent (sorted as a set) — a cheap, deterministic way to detect
     * "no progress since the last round".
     *
     * @param list<Cluster> $clusters
     */
    private function signature(array $clusters): string
    {
        $parts = [];
        foreach ($clusters as $cluster) {
            $parts[] = $cluster->name . ':' . implode(',', $cluster->members);
        }
        sort($parts, SORT_STRING);

        return implode('|', $parts);
    }

    /**
     * @param list<Cluster>        $clusters
     * @param array<string, true>  $unsplittable
     *
     * @return list<Cluster>
     */
    private function split(array $clusters, Clusterer $splitter, array &$unsplittable): array
    {
        $result = [];
        foreach ($clusters as $cluster) {
            foreach ($this->splitRecursively($cluster, $splitter, $unsplittable) as $part) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * Re-splits an oversized cluster via the strategy, recursively (Louvain
     * recursive when that is the strategy, plan §6.4). Each level must strictly
     * shrink the largest piece, so recursion is bounded and terminates.
     *
     * @param array<string, true> $unsplittable
     *
     * @return list<Cluster>
     */
    private function splitRecursively(Cluster $cluster, Clusterer $splitter, array &$unsplittable): array
    {
        if ($cluster->size() <= $this->maxSize) {
            return [$cluster];
        }

        $key = implode(',', $cluster->members);
        if (isset($unsplittable[$key])) {
            return [$cluster];
        }

        $parts = $splitter->cluster($cluster->members);
        $largestPart = 0;
        $slots = 0;
        $covered = [];
        foreach ($parts as $part) {
            $largestPart = max($largestPart, $part->size());
            $slots += $part->size();
            foreach ($part->members as $member) {
                $covered[$member] = true;
            }
        }

        // Accept the split only if it shrinks the largest piece AND is an exact
        // partition of the original members: same total slot count (no
        // duplicates) and every original member present (nothing dropped or
        // foreign). Otherwise leave the component intact.
        $exactCover = $slots === $cluster->size();
        foreach ($cluster->members as $member) {
            if (!isset($covered[$member])) {
                $exactCover = false;
                break;
            }
        }

        if (count($parts) <= 1 || $largestPart >= $cluster->size() || !$exactCover) {
            if ($splitter instanceof AutoClusterer) {
                $splitter->discardLastDecision();
            }
            $unsplittable[$key] = true;

            return [$cluster];
        }

        $result = [];
        foreach ($parts as $part) {
            foreach ($this->splitRecursively($part, $splitter, $unsplittable) as $piece) {
                $result[] = $piece;
            }
        }

        return $result;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<Cluster>
     */
    private function merge(array $clusters, Graph $graph): array
    {
        while (count($clusters) > 1) {
            $clusters = array_values($clusters);
            $index = $this->smallestUndersized($clusters);
            if ($index === null) {
                break;
            }

            $small = $clusters[$index];
            unset($clusters[$index]);
            $clusters = array_values($clusters);

            $targetIndex = $this->mostConnected($small, $clusters, $graph);

            if ($targetIndex === null) {
                $clusters = $this->intoMisc($clusters, $small);
                continue;
            }

            $target = $clusters[$targetIndex];
            $clusters[$targetIndex] = new Cluster(
                $target->name,
                array_merge($target->members, $small->members),
            );
        }

        return array_values($clusters);
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function smallestUndersized(array $clusters): ?int
    {
        $best = null;
        foreach ($clusters as $i => $cluster) {
            if ($cluster->name === self::MISC || $cluster->size() >= $this->minSize) {
                continue;
            }
            if (
                $best === null
                || $cluster->size() < $clusters[$best]->size()
                || ($cluster->size() === $clusters[$best]->size() && strcmp($cluster->name, $clusters[$best]->name) < 0)
            ) {
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * @param list<Cluster> $clusters candidate targets (must exclude $small)
     */
    private function mostConnected(Cluster $small, array $clusters, Graph $graph): ?int
    {
        $clusterOf = [];
        foreach ($clusters as $i => $cluster) {
            foreach ($cluster->members as $member) {
                $clusterOf[$member] = $i;
            }
        }

        /** @var array<int, int> $edges */
        $edges = [];
        foreach ($small->members as $member) {
            foreach ($graph->neighbours($member) as $neighbour) {
                if (isset($clusterOf[$neighbour])) {
                    $edges[$clusterOf[$neighbour]] = ($edges[$clusterOf[$neighbour]] ?? 0) + 1;
                }
            }
        }

        $best = null;
        $bestCount = 0;
        foreach ($edges as $i => $count) {
            // Never let a merge push its target over max-size — that would
            // just recreate the problem this refiner exists to prevent, one
            // merge at a time. A candidate this size-constrained out of is
            // simply not a candidate; $small falls through to misc instead.
            if ($clusters[$i]->size() + $small->size() > $this->maxSize) {
                continue;
            }
            if (
                $best === null
                || $count > $bestCount
                || ($count === $bestCount && strcmp($clusters[$i]->name, $clusters[$best]->name) < 0)
            ) {
                $best = $i;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<Cluster>
     */
    private function intoMisc(array $clusters, Cluster $small): array
    {
        foreach ($clusters as $i => $cluster) {
            if ($cluster->name === self::MISC) {
                $clusters[$i] = new Cluster(self::MISC, array_merge($cluster->members, $small->members));

                return $clusters;
            }
        }

        $clusters[] = new Cluster(self::MISC, $small->members);

        return $clusters;
    }
}
