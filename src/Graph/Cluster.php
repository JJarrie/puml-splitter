<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * A named group of node aliases. Members are always stored sorted by alias for
 * determinism.
 */
final readonly class Cluster
{
    /** @var list<string> */
    public array $members;

    /**
     * @param list<string> $members
     */
    public function __construct(
        public string $name,
        array $members,
    ) {
        // No silent de-duplication: members are expected to be distinct nodes,
        // so a duplicate signals an upstream partition bug that should surface
        // (e.g. via the coverage assertions) rather than be masked.
        $sorted = $members;
        sort($sorted, SORT_STRING);
        $this->members = $sorted;
    }

    public function size(): int
    {
        return count($this->members);
    }

    public function contains(string $alias): bool
    {
        return in_array($alias, $this->members, true);
    }
}
