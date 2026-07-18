<?php

declare(strict_types=1);

namespace PumlSplitter\Puml;

use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;

/**
 * Turns raw PlantUML class-diagram text into an immutable {@see Document}.
 *
 * Line-oriented and tolerant: unrecognized lines are kept as passthrough and
 * reported as warnings rather than causing a failure. Class bodies are captured
 * verbatim so they can later be re-emitted byte-identically.
 */
final class Parser
{
    private const DECLARATION =
        '/^\s*(abstract class|class|interface|enum)\s+"([^"]+)"\s+as\s+(\S+)\s*(.*?)\s*$/';

    private const RELATION =
        '/^\s*(\S+)\s+(\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)(?:\s*:\s*(.+?))?\s*$/';

    private const PACKAGE_OPEN =
        '/^\s*package\s+(?:"([^"]+)"|(\S+))(?:\s+as\s+(\S+))?\s*\{\s*$/';

    /** @var list<string> */
    private array $warnings = [];

    public function parse(string $content): Document
    {
        $this->warnings = [];

        $lines = explode("\n", $content);
        $lineCount = count($lines);

        $startLine = '';
        // Tolerate fragments with no @startuml: begin parsing immediately when
        // the marker is absent, otherwise wait for it (and capture header lines).
        $started = !$this->containsStartMarker($content);
        $seenDeclaration = false;

        /** @var list<string> */
        $headerLines = [];
        /** @var array<string, ClassDeclaration> */
        $classes = [];
        /** @var list<Relation> */
        $relations = [];
        /** @var list<array{line: int, text: string}> */
        $passthrough = [];
        /** @var list<string> package name stack for flattening nested blocks */
        $packageStack = [];

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $this->stripEol($lines[$i]);
            $lineNumber = $i + 1;
            $trimmed = trim($line);

            if (!$started) {
                if (str_starts_with($trimmed, '@startuml')) {
                    $startLine = $line;
                    $started = true;
                }

                continue;
            }

            if ($trimmed === '@enduml') {
                break;
            }

            if (preg_match(self::PACKAGE_OPEN, $line, $m, PREG_UNMATCHED_AS_NULL) === 1) {
                $packageStack[] = $m[1] ?? $m[2] ?? '';

                continue;
            }

            if ($trimmed === '}') {
                if ($packageStack !== []) {
                    array_pop($packageStack);
                } else {
                    $passthrough[] = ['line' => $lineNumber, 'text' => $line];
                    $this->warn($lineNumber, $line);
                }

                continue;
            }

            if (preg_match(self::DECLARATION, $line, $m) === 1) {
                $seenDeclaration = true;
                $alias = $m[3];
                $rest = $m[4];
                $bodyLines = null;

                // Split an optional decoration (stereotype/colour) from the body.
                $bracePos = strpos($rest, '{');
                $decoration = trim($bracePos === false ? $rest : substr($rest, 0, $bracePos));
                $bodyPart = $bracePos === false ? '' : substr($rest, $bracePos);

                if ($bodyPart === '{') {
                    // Body opens here and continues on following lines.
                    [$bodyLines, $i] = $this->collectBody($lines, $i + 1, $lineCount);
                } elseif ($bodyPart !== '' && str_ends_with($bodyPart, '}')) {
                    // Whole body sits inline on this line, e.g. `{}` or `{ +x }`.
                    $inner = trim(substr($bodyPart, 1, -1));
                    $bodyLines = $inner === '' ? [] : [$inner];
                } elseif ($bodyPart !== '') {
                    // `{` opens a body whose closing brace is on a later line.
                    [$bodyLines, $i] = $this->collectBody($lines, $i + 1, $lineCount);
                }

                $stereotype = $this->parseDecoration($decoration, $lineNumber);

                if (isset($classes[$alias])) {
                    $this->warnings[] = sprintf('Duplicate alias "%s" on line %d ignored.', $alias, $lineNumber);

                    continue;
                }

                $classes[$alias] = new ClassDeclaration(
                    alias: $alias,
                    name: $m[2],
                    kind: ClassKind::fromKeyword($m[1]),
                    bodyLines: $bodyLines,
                    package: $packageStack === [] ? null : $packageStack[array_key_last($packageStack)],
                    stereotype: $stereotype,
                );

                continue;
            }

            if (preg_match(self::RELATION, $line, $m, PREG_UNMATCHED_AS_NULL) === 1) {
                $label = $m[4] ?? null;
                $relations[] = new Relation(
                    source: $m[1],
                    arrow: $m[2],
                    target: $m[3],
                    label: $label,
                    raw: $line,
                );

                continue;
            }

            // Everything between @startuml and the first declaration is a header,
            // re-injected verbatim into every output file.
            if (!$seenDeclaration) {
                $headerLines[] = $line;

                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            $passthrough[] = ['line' => $lineNumber, 'text' => $line];
            $this->warn($lineNumber, $line);
        }

        return new Document(
            startLine: $startLine,
            headerLines: $headerLines,
            classes: $classes,
            relations: $relations,
            passthrough: $passthrough,
        );
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Collects a class body verbatim until its closing brace (on its own line).
     *
     * @param list<string> $lines
     *
     * @return array{0: list<string>, 1: int} the body lines and the index of the
     *                                         closing `}` (so the caller's loop resumes after it)
     */
    private function collectBody(array $lines, int $start, int $lineCount): array
    {
        /** @var list<string> */
        $body = [];

        for ($i = $start; $i < $lineCount; $i++) {
            $line = $this->stripEol($lines[$i]);
            $trimmed = trim($line);

            if ($trimmed === '}') {
                return [$body, $i];
            }

            // A missing closing brace must not swallow the diagram terminator (or a
            // following diagram); stop at @enduml and let the caller reprocess it.
            if ($trimmed === '@enduml') {
                $this->warnings[] = 'Unterminated class body: reached @enduml before a closing brace near line ' . $start . '.';

                return [$body, $i - 1];
            }

            $body[] = $line;
        }

        // Unterminated body running to end of input.
        $this->warnings[] = 'Unterminated class body starting near line ' . $start . '.';

        return [$body, $lineCount - 1];
    }

    /**
     * Interprets the text between the alias and the body: a `<<…>>` stereotype
     * and/or a `#colour` spot are accepted silently; anything else is genuine
     * trailing junk and warns. Returns the stereotype, if present.
     */
    private function parseDecoration(string $decoration, int $lineNumber): ?string
    {
        if ($decoration === '') {
            return null;
        }

        if (preg_match('/^(?:<<[^>]*>>|#[0-9A-Za-z]+|\s)+$/', $decoration) !== 1) {
            $this->warnings[] = sprintf(
                'Ignored trailing content after declaration on line %d: %s',
                $lineNumber,
                $decoration,
            );

            return null;
        }

        return preg_match('/<<[^>]*>>/', $decoration, $sm) === 1 ? $sm[0] : null;
    }

    private function containsStartMarker(string $content): bool
    {
        return preg_match('/^\s*@startuml\b/m', $content) === 1;
    }

    private function stripEol(string $line): string
    {
        return rtrim($line, "\r");
    }

    private function warn(int $lineNumber, string $text): void
    {
        $this->warnings[] = sprintf('Unrecognized line %d: %s', $lineNumber, trim($text));
    }
}
