<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Records how the `auto` strategy chose between prefix and leiden for one split,
 * for the dry-run report.
 */
final readonly class AutoDecision
{
    public function __construct(
        public string $chosen,
        public int $size,
        public int $prefixCut,
        public int $leidenCut,
        public bool $prefixSatisfies,
        public bool $leidenSatisfies,
    ) {
    }
}
