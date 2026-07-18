#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Anonymizes a PlantUML class diagram.
 *
 * Reads a .puml file, collects every declared class/interface/enum alias, builds
 * a stable alias -> TypeNNN table (in order of first appearance), and applies a
 * whole-word replacement over the *entire* file: declarations, class bodies,
 * relations, the quoted display names, and the member types that reference other
 * classes. Nothing else is touched, so the output keeps the same number of lines
 * and the same relations (renamed consistently).
 *
 * As a safety net it recomputes the sorted in/out degree distributions of the
 * input and the output; if they differ the run fails with exit code 1.
 *
 * Usage:
 *   php scripts/anonymize-puml.php [--scrub-members] <input.puml> [output.puml]
 *
 * With no output path the anonymized diagram is written to STDOUT. Diagnostics
 * and the verification result always go to STDERR.
 *
 * --scrub-members additionally regenerates every class body with generic members
 * derived from the node's outgoing `..>` relations, erasing real field, method
 * and parameter names. Relations are untouched, so the graph (and the degree
 * check) still holds; body line counts change in this mode.
 */

/**
 * @param non-empty-string $message
 */
function fail(string $message): never
{
    fwrite(STDERR, 'error: ' . $message . PHP_EOL);
    exit(1);
}

// Declaration line: `<kind> "<display>" as <alias>` (indentation-tolerant).
const DECL_ALIAS_RE = '/^\s*(?:abstract class|class|interface|enum)\s+"[^"]*"\s+as\s+(\S+)/m';
const DECL_LINE_RE = '/^(\s*(?:abstract class|class|interface|enum)\s+)"[^"]*"(\s+as\s+)(\S+)/m';

// Relation line: `<source> <arrow> <target>[ : label]`.
const RELATION_RE = '/^\s*(\S+)\s+(?:\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)(?:\s*:\s*.+?)?\s*$/m';

/**
 * Builds the alias -> TypeNNN map in order of first appearance.
 *
 * @return array<string, string>
 */
function buildAliasMap(string $content): array
{
    if (preg_match_all(DECL_ALIAS_RE, $content, $matches) === false) {
        fail('failed to scan declarations');
    }

    $map = [];
    $counter = 0;
    $width = 3;
    foreach ($matches[1] as $alias) {
        if (isset($map[$alias])) {
            continue;
        }
        $counter++;
        $width = max($width, strlen((string) $counter));
    }

    // Second pass so the numeric width is known up front and stays stable.
    $counter = 0;
    foreach ($matches[1] as $alias) {
        if (isset($map[$alias])) {
            continue;
        }
        $counter++;
        $map[$alias] = 'Type' . str_pad((string) $counter, $width, '0', STR_PAD_LEFT);
    }

    return $map;
}

/**
 * Applies the whole-word alias replacement, then forces every quoted display
 * name to match its (already renamed) alias.
 *
 * @param array<string, string> $map
 */
function anonymize(string $content, array $map): string
{
    if ($map === []) {
        return $content;
    }

    // Longest aliases first so a short alias never pre-empts a longer one.
    $aliases = array_keys($map);
    usort($aliases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    $pattern = '/\b(' . implode('|', array_map(
        static fn (string $a): string => preg_quote($a, '/'),
        $aliases,
    )) . ')\b/';

    $result = preg_replace_callback(
        $pattern,
        static fn (array $m): string => $map[$m[1]],
        $content,
    );
    if ($result === null) {
        fail('alias replacement failed');
    }

    // The quoted display name is not an alias token when it differs from the
    // alias (e.g. package-nested classes), so align it explicitly.
    $result = preg_replace_callback(
        DECL_LINE_RE,
        static fn (array $m): string => $m[1] . '"' . $m[3] . '"' . $m[2] . $m[3],
        $result,
    );
    if ($result === null) {
        fail('display-name normalization failed');
    }

    return $result;
}

/**
 * Sorted in/out degree distributions over the relation graph, plus edge count.
 *
 * @return array{edges: int, in: list<int>, out: list<int>}
 */
function degreeDistribution(string $content): array
{
    if (preg_match_all(RELATION_RE, $content, $matches, PREG_SET_ORDER) === false) {
        fail('failed to scan relations');
    }

    /** @var array<string, int> $in */
    $in = [];
    /** @var array<string, int> $out */
    $out = [];
    $edges = 0;
    foreach ($matches as $m) {
        $source = $m[1];
        $target = $m[2];
        $out[$source] = ($out[$source] ?? 0) + 1;
        $out[$target] ??= 0;
        $in[$target] = ($in[$target] ?? 0) + 1;
        $in[$source] ??= 0;
        $edges++;
    }

    $inValues = array_values($in);
    $outValues = array_values($out);
    sort($inValues);
    sort($outValues);

    return ['edges' => $edges, 'in' => $inValues, 'out' => $outValues];
}

/**
 * Regenerates every class body with generic members derived from the node's
 * outgoing dependency (`..>`) relations, erasing real field/method/parameter
 * names. Relations are left untouched, so the graph is preserved; only body
 * line counts change.
 */
function scrubMembers(string $content): string
{
    $arrowRe = '/^\s*(\S+)\s+(\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)/';

    /** @var array<string, list<string>> $outDeps */
    $outDeps = [];
    foreach (explode("\n", $content) as $line) {
        if (preg_match($arrowRe, $line, $m) === 1 && $m[2] === '..>') {
            $outDeps[$m[1]][] = $m[3];
        }
    }

    $declOpenRe = '/^(\s*)(abstract class|class|interface|enum)\s+"[^"]*"\s+as\s+(\S+)\s*\{\s*$/';
    $lines = explode("\n", $content);
    $count = count($lines);
    $out = [];

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if (preg_match($declOpenRe, $line, $m) !== 1) {
            $out[] = $line;
            continue;
        }

        $indent = $m[1];
        $kind = $m[2];
        $alias = $m[3];
        $bodyIndent = $indent . '  ';
        $out[] = $line;

        if ($kind !== 'enum') {
            $targets = $outDeps[$alias] ?? [];
            if ($targets !== []) {
                $params = [];
                foreach ($targets as $k => $target) {
                    $field = 'attr' . ($k + 1);
                    $out[] = $bodyIndent . '+' . $field . ' : ?' . $target;
                    $params[] = $field;
                }
                $out[] = $bodyIndent . '+__construct(' . implode(', ', $params) . ')';
            } else {
                $out[] = $bodyIndent . '+getElementName(elementType)';
            }
        }

        // Drop the original body, keeping its closing brace verbatim.
        for ($i++; $i < $count; $i++) {
            if (trim(rtrim($lines[$i], "\r")) === '}') {
                $out[] = $lines[$i];
                break;
            }
        }
    }

    return implode("\n", $out);
}

// ---- main ---------------------------------------------------------------

$scrubMembers = false;
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--scrub-members') {
        $scrubMembers = true;
        continue;
    }
    $positional[] = $arg;
}

$inputPath = $positional[0] ?? null;
$outputPath = $positional[1] ?? null;

if ($inputPath === null) {
    fail('usage: php scripts/anonymize-puml.php [--scrub-members] <input.puml> [output.puml]');
}
if (!is_file($inputPath) || !is_readable($inputPath)) {
    fail(sprintf('cannot read input file: %s', $inputPath));
}

$content = file_get_contents($inputPath);
if ($content === false) {
    fail(sprintf('cannot read input file: %s', $inputPath));
}

$map = buildAliasMap($content);
$output = anonymize($content, $map);
if ($scrubMembers) {
    $output = scrubMembers($output);
}

// Invariant: identical sorted degree distributions. The line count is also
// preserved unless bodies were regenerated by --scrub-members.
$inputLines = substr_count($content, "\n");
$outputLines = substr_count($output, "\n");
if (!$scrubMembers && $inputLines !== $outputLines) {
    fail(sprintf('line count changed: input=%d output=%d', $inputLines, $outputLines));
}

$before = degreeDistribution($content);
$after = degreeDistribution($output);

if ($before !== $after) {
    fwrite(STDERR, sprintf(
        "verification FAILED: degree distributions differ\n  input : edges=%d in=%s out=%s\n  output: edges=%d in=%s out=%s\n",
        $before['edges'],
        json_encode($before['in']),
        json_encode($before['out']),
        $after['edges'],
        json_encode($after['in']),
        json_encode($after['out']),
    ));
    exit(1);
}

if ($outputPath !== null) {
    if (file_put_contents($outputPath, $output) === false) {
        fail(sprintf('cannot write output file: %s', $outputPath));
    }
} else {
    fwrite(STDOUT, $output);
}

fwrite(STDERR, sprintf(
    "ok: %d aliases anonymized, %d relations preserved, degree distributions identical\n",
    count($map),
    $before['edges'],
));
exit(0);
