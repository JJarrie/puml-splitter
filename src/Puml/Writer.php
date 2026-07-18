<?php

declare(strict_types=1);

namespace PumlSplitter\Puml;

use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Relation;

/**
 * Emits model objects back to PlantUML lines. Class bodies are re-emitted
 * byte-identically to the input (plan §11); declaration and relation lines are
 * reconstructed with a fixed two-space indent (only bodies must be verbatim).
 */
final class Writer
{
    private const INDENT = '  ';

    /**
     * A full declaration: header line, verbatim body, closing brace.
     *
     * @return list<string>
     */
    public function declaration(ClassDeclaration $class, ?string $stereotype = null, ?string $color = null): array
    {
        $head = $this->head($class->kind, $class->name, $class->alias, $stereotype, $color);

        if ($class->bodyLines === null) {
            return [$head];
        }

        $lines = [$head . ' {'];
        foreach ($class->bodyLines as $bodyLine) {
            $lines[] = $bodyLine;
        }
        $lines[] = self::INDENT . '}';

        return $lines;
    }

    /**
     * A bodyless stub declaration (boundary/external nodes).
     */
    public function stub(ClassKind $kind, string $name, string $alias, ?string $stereotype = null, ?string $color = null): string
    {
        return $this->head($kind, $name, $alias, $stereotype, $color);
    }

    public function relation(Relation $relation): string
    {
        $line = self::INDENT . $relation->source . ' ' . $relation->arrow . ' ' . $relation->target;
        if ($relation->label !== null) {
            $line .= ' : ' . $relation->label;
        }

        return $line;
    }

    private function head(ClassKind $kind, string $name, string $alias, ?string $stereotype, ?string $color): string
    {
        // Class names from the parser never contain quotes; this only guards the
        // fallback where an undeclared alias is used as the display name.
        $head = self::INDENT . $kind->value . ' "' . str_replace('"', "'", $name) . '" as ' . $alias;
        if ($stereotype !== null) {
            $head .= ' ' . $stereotype;
        }
        if ($color !== null) {
            $head .= ' ' . $color;
        }

        return $head;
    }
}
