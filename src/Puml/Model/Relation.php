<?php

declare(strict_types=1);

namespace PumlSplitter\Puml\Model;

/**
 * A directed relation between two nodes, identified by their PlantUML aliases.
 *
 * Inheritance arrows (`<|--`, `<|..`) are relations too, on equal footing with
 * dependencies. `$raw` is the original source line, kept for diagnostics —
 * {@see \PumlSplitter\Puml\Writer} re-emits relations by reconstructing them
 * from the other fields, not by replaying `$raw`.
 *
 * `$sourceMultiplicity`/`$targetMultiplicity` capture an optional quoted UML
 * multiplicity annotation flanking the arrow (plan §5 amendment), e.g.
 * `Source "1" ..> "*" Target`; both are `null` when absent.
 */
final readonly class Relation
{
    public function __construct(
        public string $source,
        public string $arrow,
        public string $target,
        public ?string $label,
        public string $raw,
        public ?string $sourceMultiplicity = null,
        public ?string $targetMultiplicity = null,
    ) {
    }
}
