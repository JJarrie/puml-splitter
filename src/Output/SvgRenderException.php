<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use RuntimeException;

/**
 * A {@see SvgRenderer::render()} failure that also reports how many `.puml`
 * files were already rendered by earlier, successful batches before the
 * failing one — batching (plan §7 amendment) means a failure no longer
 * implies "nothing was rendered", and the caller needs that count to tell
 * the user what state the output directory is actually in.
 */
final class SvgRenderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $renderedCount,
        public readonly int $totalCount,
    ) {
        parent::__construct($message);
    }
}
