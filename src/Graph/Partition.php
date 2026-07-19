<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * The result of the clustering pipeline: the clusters, the extracted hubs, and
 * the edge accounting used both for the dry-run plan and the "no lost edges"
 * invariant (`internal + inter-cluster + hub == total input edges`).
 */
final readonly class Partition
{
    /**
     * @param list<Cluster> $clusters
     * @param list<Hub>     $hubs
     * @param list<int>     $internalByCluster edges internal to each cluster, parallel to $clusters
     * @param list<int>     $externalByCluster inter-cluster edges incident to each cluster, parallel to $clusters
     * @param list<string>  $warnings          informational warnings from the pipeline (plan §6ter: a
     *                                         mapped cluster outside [min-size, max-size] is reported
     *                                         here rather than silently left alone or force-refined)
     */
    public function __construct(
        public array $clusters,
        public array $hubs,
        public int $internalEdges,
        public int $interClusterEdges,
        public int $hubEdges,
        public array $internalByCluster,
        public array $externalByCluster,
        public array $warnings = [],
    ) {
    }

    public function totalEdges(): int
    {
        return $this->internalEdges + $this->interClusterEdges + $this->hubEdges;
    }

    /**
     * @return list<Cluster> clusters larger than the given bound
     */
    public function oversizedClusters(int $maxSize): array
    {
        return array_values(array_filter(
            $this->clusters,
            static fn (Cluster $c): bool => $c->size() > $maxSize,
        ));
    }
}
