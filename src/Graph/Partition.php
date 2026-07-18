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
     */
    public function __construct(
        public array $clusters,
        public array $hubs,
        public int $internalEdges,
        public int $interClusterEdges,
        public int $hubEdges,
        public array $internalByCluster,
        public array $externalByCluster,
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
