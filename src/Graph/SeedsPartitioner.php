<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

use PumlSplitter\Puml\Model\Document;

/**
 * Runs the `--strategy=seeds` pipeline (plan §6ter): clusters grow outward
 * from human-meaningful aggregate roots instead of optimizing a
 * domain-blind metric. Like {@see MapPartitioner}, this is a dedicated
 * top-level orchestrator rather than a {@see Clusterer} plugged into
 * {@see Partitioner} — seed selection and BFS growth are inherently
 * whole-graph operations (a node's nearest seed may sit in a different
 * connected component's reach, and "unreached anywhere" only means
 * something at that scope), not a per-connected-component concern the way
 * `prefix`/`louvain` are. Unlike `map`, seed-grown clusters are NOT exempt
 * from {@see ClusterRefiner} — plan §6ter is explicit that only `map`
 * clusters get that exemption.
 *
 * Determinism is load-bearing here: distances are computed once per seed via
 * independent BFS (order of computation doesn't matter, only the final
 * per-node comparison across all seeds does — this is what makes growth
 * truly simultaneous rather than biased toward whichever seed a naive
 * sequential "claim everything reachable" loop processes first), and both
 * tie-break levels are applied per plan §6ter: (1) the seed toward which the
 * node has the most shortest-path predecessors among its direct neighbours
 * — the plan's literal "most shared direct edges" is only meaningful at
 * distance 1, so this predecessor count is the non-degenerate reading of it
 * at any distance, and §6ter was amended to say so explicitly — then (2)
 * alphabetical seed order.
 */
final class SeedsPartitioner
{
    /**
     * @param list<string> $explicitSeeds
     */
    public function __construct(
        private readonly HubDetector $hubDetector,
        private readonly Clusterer $fallbackStrategy,
        private readonly ClusterRefiner $refiner,
        private readonly array $explicitSeeds,
        private readonly int $seedThreshold,
    ) {
    }

    public function partition(Graph $graph, Document $document): SeedsPartitionResult
    {
        $hubs = $this->hubDetector->detect($graph);
        $hubSet = Hub::aliasSet($hubs);
        $nonHub = array_values(array_filter($graph->nodes(), static fn (string $a): bool => !isset($hubSet[$a])));
        $nonHubSet = array_fill_keys($nonHub, true);

        $seeds = $this->resolveSeeds($graph, $nonHub, $nonHubSet);
        if ($seeds instanceof SeedsPartitionResult) {
            return $seeds;
        }

        /** @var array<string, array<string, int>> $distanceFrom seed => (alias => hop distance) */
        $distanceFrom = [];
        foreach ($seeds as $seed) {
            $distanceFrom[$seed] = $this->bfsDistances($graph, $seed, $nonHubSet);
        }

        $groups = $this->assign($seeds, $nonHub, $distanceFrom, $graph, $nonHubSet);

        $clusters = [];
        foreach ($groups as $name => $members) {
            $clusters[] = new Cluster($name, $members);
        }
        $clusters = $this->refiner->refine($clusters, $graph, $this->fallbackStrategy);

        return new SeedsPartitionResult((new EdgeAccountant())->account($clusters, $hubs, $document), null);
    }

    /**
     * @param list<string>         $nonHub
     * @param array<string, true>  $nonHubSet
     *
     * @return list<string>|SeedsPartitionResult
     */
    private function resolveSeeds(Graph $graph, array $nonHub, array $nonHubSet)
    {
        if ($this->explicitSeeds !== []) {
            if (in_array(ClusterRefiner::MISC, $this->explicitSeeds, true)) {
                return new SeedsPartitionResult(null, sprintf(
                    '--seed cannot use "%s": that name is reserved for the refiner\'s catch-all cluster.',
                    ClusterRefiner::MISC,
                ));
            }

            // De-duplicate before validating so a repeated --seed=X --seed=X
            // for an unknown alias is reported once, not counted twice.
            $requested = array_values(array_unique($this->explicitSeeds));

            $unknown = array_values(array_filter(
                $requested,
                static fn (string $s): bool => !isset($nonHubSet[$s]),
            ));
            if ($unknown !== []) {
                return $this->fatalUnknownSeeds($unknown, $nonHub);
            }

            // Deterministic (alphabetical) order — the CLI order of repeated
            // --seed flags must not affect the result.
            sort($requested, SORT_STRING);

            return $requested;
        }

        $auto = array_values(array_filter(
            $nonHub,
            fn (string $a): bool => $a !== ClusterRefiner::MISC && $graph->outDegree($a) >= $this->seedThreshold,
        ));
        sort($auto, SORT_STRING);

        if ($auto === []) {
            $maxOutDegree = 0;
            foreach ($nonHub as $alias) {
                $maxOutDegree = max($maxOutDegree, $graph->outDegree($alias));
            }

            return new SeedsPartitionResult(null, sprintf(
                'No seeds available: no non-hub node reaches --seed-threshold=%d (observed max out-degree: %d). '
                    . 'Pass --seed=ALIAS explicitly or lower --seed-threshold.',
                $this->seedThreshold,
                $maxOutDegree,
            ));
        }

        return $auto;
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $candidates
     */
    private function fatalUnknownSeeds(array $unknown, array $candidates): SeedsPartitionResult
    {
        $parts = [];
        foreach ($unknown as $alias) {
            $suggestions = $this->suggest($alias, $candidates);
            $parts[] = $suggestions === []
                ? sprintf('"%s"', $alias)
                : sprintf('"%s" (did you mean: %s?)', $alias, implode(', ', $suggestions));
        }

        return new SeedsPartitionResult(null, sprintf(
            '--seed names %d alias(es) not present in the graph (or excluded as hubs): %s.',
            count($unknown),
            implode(', ', $parts),
        ));
    }

    private const SUGGESTION_MAX_DISTANCE = 3;

    /**
     * Simple edit-distance suggestion (plan §6ter: "optionnelle mais
     * appréciée") — the closest candidates by Levenshtein distance, capped
     * to a distance where the suggestion is plausibly a typo rather than
     * noise.
     *
     * @param list<string> $candidates
     *
     * @return list<string> up to 3 closest candidates, sorted by (distance, alias)
     */
    private function suggest(string $alias, array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $distance = levenshtein($alias, $candidate);
            if ($distance <= self::SUGGESTION_MAX_DISTANCE) {
                $scored[] = [$distance, $candidate];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

        return array_map(static fn (array $s): string => $s[1], array_slice($scored, 0, 3));
    }

    /**
     * @param array<string, true> $nonHubSet
     *
     * @return array<string, int> alias => hop distance from $seed (only reachable nodes)
     */
    private function bfsDistances(Graph $graph, string $seed, array $nonHubSet): array
    {
        $distance = [$seed => 0];
        $queue = [$seed];
        while ($queue !== []) {
            $node = array_shift($queue);
            foreach ($graph->neighbours($node) as $next) {
                if (!isset($nonHubSet[$next]) || isset($distance[$next])) {
                    continue;
                }
                $distance[$next] = $distance[$node] + 1;
                $queue[] = $next;
            }
        }

        return $distance;
    }

    /**
     * @param list<string>                       $seeds
     * @param list<string>                       $nonHub
     * @param array<string, array<string, int>>  $distanceFrom
     * @param array<string, true>                $nonHubSet
     *
     * @return array<string, list<string>> group name (seed alias, or "misc") => members
     */
    private function assign(array $seeds, array $nonHub, array $distanceFrom, Graph $graph, array $nonHubSet): array
    {
        $groups = [];
        foreach ($nonHub as $alias) {
            $best = $this->closestSeed($alias, $seeds, $distanceFrom, $graph, $nonHubSet);
            $groups[$best ?? ClusterRefiner::MISC][] = $alias;
        }

        return $groups;
    }

    /**
     * @param list<string>                       $seeds
     * @param array<string, array<string, int>>  $distanceFrom
     * @param array<string, true>                $nonHubSet
     */
    private function closestSeed(string $alias, array $seeds, array $distanceFrom, Graph $graph, array $nonHubSet): ?string
    {
        $minDistance = null;
        foreach ($seeds as $seed) {
            $d = $distanceFrom[$seed][$alias] ?? null;
            if ($d === null) {
                continue;
            }
            if ($minDistance === null || $d < $minDistance) {
                $minDistance = $d;
            }
        }
        if ($minDistance === null) {
            return null; // unreached by any seed
        }

        $candidates = array_values(array_filter(
            $seeds,
            static fn (string $seed): bool => ($distanceFrom[$seed][$alias] ?? null) === $minDistance,
        ));
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Tie-break level 1: the seed toward which the node has the most
        // shortest-path predecessors among its direct (non-hub) neighbours —
        // a neighbour Y counts for seed S when Y sits exactly one hop closer
        // to S than the node itself (distanceFrom[S][Y] === minDistance - 1).
        $neighbours = array_values(array_filter(
            $graph->neighbours($alias),
            static fn (string $n): bool => isset($nonHubSet[$n]),
        ));

        $bestScore = -1;
        $bestSeeds = [];
        foreach ($candidates as $seed) {
            $score = 0;
            foreach ($neighbours as $neighbour) {
                if (($distanceFrom[$seed][$neighbour] ?? null) === $minDistance - 1) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSeeds = [$seed];
            } elseif ($score === $bestScore) {
                $bestSeeds[] = $seed;
            }
        }

        // Tie-break level 2: alphabetical seed order (deterministic).
        sort($bestSeeds, SORT_STRING);

        return $bestSeeds[0];
    }
}
