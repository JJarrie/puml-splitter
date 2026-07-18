<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * A cluster as seen by the overview/index generators: its slug, display name,
 * and member aliases (sorted).
 */
final readonly class ClusterView
{
    /**
     * @param list<string> $members
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $members,
    ) {
    }

    public function size(): int
    {
        return count($this->members);
    }
}
