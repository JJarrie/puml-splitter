<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

use PumlSplitter\Puml\Model\Document;

/**
 * Runs the clustering pipeline (plan §6): hub removal → connected components →
 * strategy split of oversized components → refinement, then accounts every input
 * relation as internal, inter-cluster, or hub.
 */
final class Partitioner
{
    public function __construct(
        private readonly HubDetector $hubDetector,
        private readonly ConnectedComponents $components,
        private readonly Clusterer $strategy,
        private readonly ClusterRefiner $refiner,
        private readonly int $maxSize,
    ) {
    }

    public function partition(Graph $graph, Document $document): Partition
    {
        $hubs = $this->hubDetector->detect($graph);
        $hubAliases = array_map(static fn (Hub $hub): string => $hub->alias, $hubs);

        $clusters = (new ComponentClusterBuilder($this->components, $this->maxSize))->build($graph, $hubAliases, $this->strategy);
        $clusters = $this->refiner->refine($clusters, $graph, $this->strategy);

        return (new EdgeAccountant())->account($clusters, $hubs, $document);
    }
}
