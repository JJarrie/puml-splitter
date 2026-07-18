<?php

declare(strict_types=1);

namespace PumlSplitter\Puml\Model;

/**
 * A single type declaration (class/interface/enum/abstract class).
 *
 * The alias is the canonical identifier; the quoted short name is display-only.
 * Body lines are stored exactly as parsed (no reformatting) so the Writer can
 * re-emit them byte-identically.
 */
final readonly class ClassDeclaration
{
    /**
     * @param list<string>|null $bodyLines exact body lines between `{` and `}`;
     *                                     `null` when the declaration has no `{ }`
     *                                     block, `[]` when the block is empty
     * @param string|null       $package  original package name (flattened metadata,
     *                                     unused in v1 logic)
     */
    public function __construct(
        public string $alias,
        public string $name,
        public ClassKind $kind,
        public ?array $bodyLines,
        public ?string $package = null,
    ) {
    }

    public function hasBody(): bool
    {
        return $this->bodyLines !== null;
    }
}
