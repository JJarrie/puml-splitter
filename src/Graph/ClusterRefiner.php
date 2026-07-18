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
 * Deterministic throughout: candidates and tie-breaks are ordered by alias.
 */
final class ClusterRefiner
{
    public const MISC = 'misc';

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
        $clusters = $this->split($clusters, $splitter);
        $clusters = $this->merge($clusters, $graph);

        usort($clusters, static fn (Cluster $a, Cluster $b): int => strcmp($a->name, $b->name) ?: strcmp($a->members[0], $b->members[0]));

        return $clusters;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<Cluster>
     */
    private function split(array $clusters, Clusterer $splitter): array
    {
        $result = [];
        foreach ($clusters as $cluster) {
            if ($cluster->size() <= $this->maxSize) {
                $result[] = $cluster;
                continue;
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

            // Accept the split only if it shrinks the largest piece AND is an
            // exact partition of the original members: same total slot count
            // (no duplicates) and every original member present (nothing dropped
            // or foreign). Otherwise leave the component intact.
            $exactCover = $slots === $cluster->size();
            foreach ($cluster->members as $member) {
                if (!isset($covered[$member])) {
                    $exactCover = false;
                    break;
                }
            }

            if (count($parts) > 1 && $largestPart < $cluster->size() && $exactCover) {
                foreach ($parts as $part) {
                    $result[] = $part;
                }
            } else {
                $result[] = $cluster;
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
