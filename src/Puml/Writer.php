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
        $head = $this->head($class->kind, $class->name, $class->alias, $stereotype, null, $color);

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
     *
     * @param string|null $link relative hyperlink target (plan §7bis navigation), e.g. "cluster-order.svg"
     */
    public function stub(ClassKind $kind, string $name, string $alias, ?string $stereotype = null, ?string $color = null, ?string $link = null): string
    {
        return $this->head($kind, $name, $alias, $stereotype, $link, $color);
    }

    /**
     * @param string|null $color    inline colour spec appended verbatim, e.g. "#RRGGBB" or
     *                               "#RRGGBB;line:RRGGBB" (plan §7bis stereotype colours)
     * @param int|null    $thickness arrow thickness override (inheritance/implementation, plan §7bis)
     */
    public function relation(Relation $relation, ?string $color = null, ?int $thickness = null): string
    {
        $arrow = $this->styleArrow($relation->arrow, $color, $thickness);
        $line = self::INDENT . $relation->source;
        if ($relation->sourceMultiplicity !== null) {
            $line .= ' "' . $relation->sourceMultiplicity . '"';
        }
        $line .= ' ' . $arrow;
        if ($relation->targetMultiplicity !== null) {
            $line .= ' "' . $relation->targetMultiplicity . '"';
        }
        $line .= ' ' . $relation->target;
        if ($relation->label !== null) {
            $line .= ' : ' . $relation->label;
        }

        return $line;
    }

    /**
     * @var array<string, string> canonical bare arrow => regex matching both
     *                            its bare form and this Writer's own bracketed
     *                            form, so a previously-styled arrow (e.g. this
     *                            tool's own output fed back in) is recognised
     *                            and re-styled rather than left with a stale
     *                            embedded style.
     */
    private const ARROW_PATTERNS = [
        '..>' => '/^\.(?:\[[^\]]*\])?\.>$/',
        '-->' => '/^-(?:\[[^\]]*\])?->$/',
        'o--' => '/^o-(?:\[[^\]]*\])?-$/',
        '*--' => '/^\*-(?:\[[^\]]*\])?-$/',
        '<|--' => '/^<\|(?:-\[[^\]]*\])?--$/',
        '<|..' => '/^<\|(?:\.\[[^\]]*\])?\.\.$/',
    ];

    /**
     * Inserts a `[style]` modifier into the arrow's trunk. Only the forms
     * this tool's own Parser recognises (plan §5/§7bis), bare or already
     * bracketed, are handled; anything else is left untouched rather than
     * risk emitting a form PlantUML — or this tool's own round-trip — can't
     * read back.
     */
    private function styleArrow(string $arrow, ?string $color, ?int $thickness): string
    {
        if ($color === null && $thickness === null) {
            return $arrow;
        }

        $base = $this->baseArrow($arrow);
        if ($base === null) {
            return $arrow;
        }

        $parts = [];
        if ($color !== null) {
            $parts[] = $color;
        }
        if ($thickness !== null) {
            $parts[] = 'thickness=' . $thickness;
        }
        $style = '[' . implode(',', $parts) . ']';

        return match ($base) {
            '..>' => '.' . $style . '.>',
            '-->' => '-' . $style . '->',
            'o--' => 'o-' . $style . '-',
            '*--' => '*-' . $style . '-',
            '<|--' => '<|-' . $style . '--',
            '<|..' => '<|.' . $style . '..',
            // baseArrow() only ever returns one of the cases above (or null,
            // already handled) — this is unreachable but keeps the match
            // total against ARROW_PATTERNS' string-keyed type.
            default => $arrow,
        };
    }

    /**
     * Classifies any arrow this tool's own Parser can produce — bare or
     * already bracketed — down to its canonical bare form.
     */
    private function baseArrow(string $arrow): ?string
    {
        foreach (self::ARROW_PATTERNS as $base => $pattern) {
            if (preg_match($pattern, $arrow) === 1) {
                return $base;
            }
        }

        return null;
    }

    private function head(ClassKind $kind, string $name, string $alias, ?string $stereotype, ?string $link, ?string $color): string
    {
        // Class names from the parser never contain quotes; this only guards the
        // fallback where an undeclared alias is used as the display name.
        $head = self::INDENT . $kind->value . ' "' . str_replace('"', "'", $name) . '" as ' . $alias;
        if ($stereotype !== null) {
            $head .= ' ' . $stereotype;
        }
        // Order matters to PlantUML: a [[link]] after an extended colour spec
        // (e.g. "#RRGGBB;line:RRGGBB") fails to parse, but before it is fine.
        if ($link !== null) {
            $head .= ' [[' . $link . ']]';
        }
        if ($color !== null) {
            $head .= ' ' . $color;
        }

        return $head;
    }
}
