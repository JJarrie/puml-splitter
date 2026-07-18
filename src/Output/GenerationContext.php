<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\Hub;
use PumlSplitter\Puml\Model\Document;

/**
 * Shared lookup tables for the cluster generators: where each node lives and how
 * each hub is treated.
 */
final readonly class GenerationContext
{
    /**
     * @param array<string, string> $clusterSlugOf non-hub alias => home cluster slug
     * @param array<string, Hub>    $hubOf         hub alias => Hub
     * @param list<string>          $additionalHeaders extra header lines (from --header)
     */
    public function __construct(
        public Document $document,
        public array $clusterSlugOf,
        public array $hubOf,
        public string $sharedTypesSlug,
        public array $additionalHeaders,
    ) {
    }
}
