<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

use PumlSplitter\Puml\Model\Document;

/**
 * Runs the `--strategy=map` pipeline (plan §6ter). Deliberately NOT a
 * {@see Clusterer} plugged into {@see Partitioner}: a per-component
 * `cluster(array $members)` call has no way to tell {@see ClusterRefiner}
 * "these specific pieces are human-assigned and must never be touched, but
 * these other ones came from the fallback and should be refined normally" —
 * Cluster carries no such flag, and retrofitting one would leak map-specific
 * exemption logic into the refiner every other strategy also uses. Instead:
 * mapped clusters are built directly from the (already-validated) map, never
 * touch {@see ConnectedComponents} or {@see ClusterRefiner} at all, and only
 * the *fallback* subset (graph nodes the map doesn't mention) goes through
 * the ordinary components → strategy → refiner pipeline, exactly as it would
 * for `auto`/`prefix`/`louvain`.
 */
final class MapPartitioner
{
    public function __construct(
        private readonly HubDetector $hubDetector,
        private readonly ConnectedComponents $components,
        private readonly Clusterer $fallbackStrategy,
        private readonly ClusterRefiner $refiner,
        private readonly int $minSize,
        private readonly int $maxSize,
    ) {
    }

    public function partition(Graph $graph, Document $document, MapFile $map): MapPartitionResult
    {
        $graphNodes = array_fill_keys($graph->nodes(), true);
        $owners = $map->aliasOwners();

        $unknownToGraph = array_values(array_diff(array_keys($owners), array_keys($graphNodes)));
        sort($unknownToGraph, SORT_STRING);

        if ($unknownToGraph !== [] && $map->fallback === MapFile::FALLBACK_ERROR) {
            return $this->fatal(sprintf(
                'Map file references %d alias(es) not present in the graph: %s.',
                count($unknownToGraph),
                implode(', ', $unknownToGraph),
            ));
        }

        $warnings = [];
        if ($unknownToGraph !== []) {
            $warnings[] = sprintf(
                'Map file references %d alias(es) not present in the graph (ignored): %s.',
                count($unknownToGraph),
                implode(', ', $unknownToGraph),
            );
        }

        // Hub detection already excludes every mapped alias (the human map
        // takes priority even over an explicit --hub — see HubDetector's
        // $excluded parameter, which the caller must populate from this same
        // map before constructing it).
        $hubs = $this->hubDetector->detect($graph);
        $hubSet = array_fill_keys(array_map(static fn (Hub $h): string => $h->alias, $hubs), true);

        [$mappedClusters, $mappedAliasSet, $boundsWarnings] = $this->buildMappedClusters($map, $graphNodes, $hubSet);
        $warnings = [...$warnings, ...$boundsWarnings];

        $fallbackNodes = array_values(array_filter(
            $graph->nodes(),
            static fn (string $alias): bool => !isset($mappedAliasSet[$alias]) && !isset($hubSet[$alias]),
        ));
        sort($fallbackNodes, SORT_STRING);

        if ($fallbackNodes !== [] && $map->fallback === MapFile::FALLBACK_ERROR) {
            return $this->fatal(sprintf(
                'Map file leaves %d graph alias(es) unmapped and --map fallback is "error": %s.',
                count($fallbackNodes),
                implode(', ', $fallbackNodes),
            ));
        }

        $fallbackClusters = $this->buildFallbackClusters($fallbackNodes, $graph, $map->fallback);

        $clusters = Cluster::sortAll([...$mappedClusters, ...$fallbackClusters]);

        return new MapPartitionResult((new EdgeAccountant())->account($clusters, $hubs, $document, $warnings), null);
    }

    /**
     * @param array<string, true> $graphNodes
     * @param array<string, true> $hubSet
     *
     * @return array{0: list<Cluster>, 1: array<string, true>, 2: list<string>}
     */
    private function buildMappedClusters(MapFile $map, array $graphNodes, array $hubSet): array
    {
        $clusters = [];
        $mappedAliasSet = [];
        $warnings = [];

        $names = array_keys($map->clusters);
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $aliases = array_values(array_filter(
                $map->clusters[$name],
                static fn (string $alias): bool => isset($graphNodes[$alias]) && !isset($hubSet[$alias]),
            ));

            if ($aliases === []) {
                continue;
            }

            foreach ($aliases as $alias) {
                $mappedAliasSet[$alias] = true;
            }

            $cluster = new Cluster($name, $aliases);
            $clusters[] = $cluster;

            // Exempt from the refiner (plan §11): report an out-of-bounds
            // mapped cluster, never touch it.
            if ($cluster->size() < $this->minSize || $cluster->size() > $this->maxSize) {
                $warnings[] = sprintf(
                    'Mapped cluster "%s" has %d member(s), outside [%d, %d] — left as mapped, not refined.',
                    $name,
                    $cluster->size(),
                    $this->minSize,
                    $this->maxSize,
                );
            }
        }

        return [$clusters, $mappedAliasSet, $warnings];
    }

    /**
     * @param list<string> $fallbackNodes
     *
     * @return list<Cluster>
     */
    private function buildFallbackClusters(array $fallbackNodes, Graph $graph, string $fallback): array
    {
        if ($fallbackNodes === []) {
            return [];
        }

        if ($fallback === MapFile::FALLBACK_MISC) {
            return [new Cluster(ClusterRefiner::MISC, $fallbackNodes)];
        }

        return $this->autoFallbackClusters($fallbackNodes, $graph);
    }

    /**
     * @param list<string> $fallbackNodes
     *
     * @return list<Cluster>
     */
    private function autoFallbackClusters(array $fallbackNodes, Graph $graph): array
    {
        $fallbackSet = array_fill_keys($fallbackNodes, true);
        $excluded = array_values(array_filter(
            $graph->nodes(),
            static fn (string $alias): bool => !isset($fallbackSet[$alias]),
        ));

        $clusters = (new ComponentClusterBuilder($this->components, $this->maxSize))
            ->build($graph, $excluded, $this->fallbackStrategy);

        return $this->refiner->refine($clusters, $graph, $this->fallbackStrategy);
    }

    private function fatal(string $message): MapPartitionResult
    {
        return new MapPartitionResult(null, $message);
    }
}
