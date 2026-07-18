<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * The outcome of running an external command.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function notFound(): bool
    {
        return $this->exitCode === 127;
    }
}
