<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * The "components → direct cluster, or split via strategy" step (plan §6.2-3):
 * connected components on the graph minus `$excluded`; a component within
 * `$maxSize` becomes a cluster directly (named by highest out-degree member,
 * plan §7), an oversized one is handed to the strategy. Shared by
 * {@see Partitioner} (excluded = hubs) and {@see MapPartitioner} (excluded =
 * hubs ∪ mapped aliases, for its `fallback=auto` subset) so this rule is
 * defined once rather than kept in sync by hand across two orchestrators.
 */
final class ComponentClusterBuilder
{
    public function __construct(
        private readonly ConnectedComponents $components,
        private readonly int $maxSize,
    ) {
    }

    /**
     * @param list<string> $excluded
     *
     * @return list<Cluster>
     */
    public function build(Graph $graph, array $excluded, Clusterer $strategy): array
    {
        $clusters = [];
        foreach ($this->components->compute($graph, $excluded) as $component) {
            if (count($component) <= $this->maxSize) {
                $clusters[] = new Cluster($graph->nameByOutDegree($component), $component);
                continue;
            }

            foreach ($strategy->cluster($component) as $cluster) {
                $clusters[] = $cluster;
            }
        }

        return $clusters;
    }
}
