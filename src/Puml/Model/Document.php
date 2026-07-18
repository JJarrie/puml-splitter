<?php

declare(strict_types=1);

namespace PumlSplitter\Puml\Model;

/**
 * Immutable in-memory representation of a parsed PlantUML class diagram.
 */
final readonly class Document
{
    /**
     * @param list<string>                    $headerLines lines between `@startuml` and the first declaration
     * @param array<string, ClassDeclaration> $classes     declarations keyed by alias
     * @param list<Relation>                  $relations
     * @param list<array{line: int, text: string}> $passthrough unrecognized lines, kept verbatim
     */
    public function __construct(
        public string $startLine,
        public array $headerLines,
        public array $classes,
        public array $relations,
        public array $passthrough = [],
    ) {
    }

    /**
     * @return array<string, ClassDeclaration>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * @return list<Relation>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    public function classCount(): int
    {
        return count($this->classes);
    }

    public function relationCount(): int
    {
        return count($this->relations);
    }

    public function getClass(string $alias): ?ClassDeclaration
    {
        return $this->classes[$alias] ?? null;
    }
}
