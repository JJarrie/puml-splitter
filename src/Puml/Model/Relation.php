<?php

declare(strict_types=1);

namespace PumlSplitter\Puml\Model;

/**
 * A directed relation between two nodes, identified by their PlantUML aliases.
 *
 * Inheritance arrows (`<|--`, `<|..`) are relations too, on equal footing with
 * dependencies. The raw source line is retained for byte-identical re-emission.
 */
final readonly class Relation
{
    public function __construct(
        public string $source,
        public string $arrow,
        public string $target,
        public ?string $label,
        public string $raw,
    ) {
    }
}
