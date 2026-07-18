<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\Hub;

/**
 * The generated `.puml` files plus the metadata the index needs.
 */
final readonly class OutputResult
{
    /**
     * @param list<GeneratedFile> $pumlFiles
     * @param list<ClusterView>   $clusterViews all generated cluster files (incl. shared-types)
     * @param list<Hub>           $hubs
     */
    public function __construct(
        public array $pumlFiles,
        public array $clusterViews,
        public array $hubs,
    ) {
    }
}
