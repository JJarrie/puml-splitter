<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Splits a set of node aliases into one or more clusters. Implementations must
 * be deterministic. Returning a single cluster means "cannot split further".
 */
interface Clusterer
{
    /**
     * @param list<string> $members
     *
     * @return list<Cluster>
     */
    public function cluster(array $members): array;
}
