<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

use PumlSplitter\Puml\Model\Document;

/**
 * Classifies every input relation as internal to a cluster, inter-cluster, or
 * touching a hub — the accounting behind the "no lost edges" invariant
 * (plan §11: `internal + inter-cluster + hub == total input edges`) and the
 * dry-run per-cluster edge counts. Shared by every partitioning strategy
 * ({@see Partitioner} and, for `--strategy=map`, {@see MapPartitioner}) so the
 * rule is defined once.
 */
final class EdgeAccountant
{
    /**
     * @param list<Cluster> $clusters
     * @param list<Hub>     $hubs
     * @param list<string>  $warnings informational warnings to attach to the resulting Partition
     */
    public function account(array $clusters, array $hubs, Document $document, array $warnings = []): Partition
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
            warnings: $warnings,
        );
    }
}
