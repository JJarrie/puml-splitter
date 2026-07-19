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
        public string $layout = 'none',
        public string $edgeColor = 'none',
        public bool $legend = false,
    ) {
    }

    /**
     * Whether the M6 presentation layer (layout pragma, stereotype recolouring,
     * navigation hyperlinks) is active at all. `--layout=none` is the single
     * switch for all of it — edge colouring and the legend are independently
     * controlled by their own flags (plan §7bis) and are not gated by this.
     */
    public function isStyled(): bool
    {
        return self::layoutIsStyled($this->layout);
    }

    /**
     * Same rule as {@see isStyled()}, usable by generators that only carry a
     * raw `$layout` string (no {@see GenerationContext} instance) — the
     * single source of truth for "is `--layout` non-'none'" either way.
     */
    public static function layoutIsStyled(string $layout): bool
    {
        return $layout !== 'none';
    }
}
