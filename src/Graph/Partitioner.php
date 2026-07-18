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

        $clusters = [];
        foreach ($this->components->compute($graph, $hubAliases) as $component) {
            if (count($component) <= $this->maxSize) {
                $clusters[] = new Cluster($graph->nameByOutDegree($component), $component);
                continue;
            }

            foreach ($this->strategy->cluster($component) as $cluster) {
                $clusters[] = $cluster;
            }
        }

        $clusters = $this->refiner->refine($clusters, $graph, $this->strategy);

        return $this->account($clusters, $hubs, $document);
    }

    /**
     * @param list<Cluster> $clusters
     * @param list<Hub>     $hubs
     */
    private function account(array $clusters, array $hubs, Document $document): Partition
    {
        $hubSet = array_fill_keys(array_map(static fn (Hub $h): string => $h->alias, $hubs), true);

        $clusterOf = [];
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster->members as $member) {
                $clusterOf[$member] = $index;
            }
        }

        $internal = 0;
        $inter = 0;
        $hubEdges = 0;
        $internalBy = array_fill(0, count($clusters), 0);
        $externalBy = array_fill(0, count($clusters), 0);

        foreach ($document->relations() as $relation) {
            $source = $relation->source;
            $target = $relation->target;

            if (isset($hubSet[$source]) || isset($hubSet[$target]) || !isset($clusterOf[$source], $clusterOf[$target])) {
                $hubEdges++;
                continue;
            }

            $cs = $clusterOf[$source];
            $ct = $clusterOf[$target];

            if ($cs === $ct) {
                $internal++;
                $internalBy[$cs]++;
            } else {
                $inter++;
                $externalBy[$cs]++;
                $externalBy[$ct]++;
            }
        }

        return new Partition(
            clusters: $clusters,
            hubs: $hubs,
            internalEdges: $internal,
            interClusterEdges: $inter,
            hubEdges: $hubEdges,
            internalByCluster: array_values($internalBy),
            externalByCluster: array_values($externalBy),
        );
    }
}
