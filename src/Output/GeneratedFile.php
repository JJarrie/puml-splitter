<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * A file to write: relative name plus its full textual content.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $name,
        public string $content,
    ) {
    }
}
