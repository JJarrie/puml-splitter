#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Anonymizes a PlantUML class diagram by CamelCase token (plan §5).
 *
 * Each class name is split into CamelCase tokens (`DateChainePenale` → Date,
 * Chaine, Penale); each distinct token is mapped to a deterministic pseudonym in
 * order of first appearance (`Date` → Tok001, `Chaine` → Tok002…); the anonymized
 * name is the recomposition, preserving separators (`Structure_ElementSequence` →
 * `Tok045_Tok012Tok078`). Two names sharing a real token therefore share the same
 * pseudonym, so the prefix structure that PrefixClusterer relies on survives — an
 * alias-level scheme (TypeNNN) would make every name prefix-equivalent and
 * degenerate the `prefix` strategy.
 *
 * The replacement is whole-word over the entire file (declarations, quoted names,
 * member types, relations); nothing else changes, so lines, relations and
 * topology are preserved.
 *
 * Usage:
 *   php scripts/anonymize-puml.php [--scrub-members] <input.puml> [output.puml]
 *
 * --scrub-members additionally regenerates every class body with generic members
 * derived from the node's outgoing `..>` relations, erasing real field, method
 * and parameter names.
 *
 * Two invariants are checked before writing (any breach → exit 1, nothing written):
 *   1. the sorted in/out degree sequence of the graph is identical before/after;
 *   2. the tokenisation structure is preserved — same token count per name, and
 *      the same token-sharing pattern across names.
 */

// Canonical CamelCase tokenizer, shared in spirit with PrefixClusterer: digits
// stay attached to their letter run so a pseudonym like "Tok001" is one token.
const TOKEN_RE = '/[A-Z]+(?![a-z])|[A-Z][a-z0-9]*|[a-z][a-z0-9]*|[0-9]+/';

const DECLARATION_RE = '/^\s*(?:abstract class|class|interface|enum)\s+"([^"]+)"\s+as\s+(\S+)/m';
const PACKAGE_RE = '/^\s*package\s+(?:"([^"]+)"|(\S+))(?:\s+as\s+(\S+))?\s*\{/m';
const RELATION_RE = '/^\s*(\S+)\s+(?:\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)(?:\s*:\s*.+?)?\s*$/m';

/**
 * @param non-empty-string $message
 */
function fail(string $message): never
{
    fwrite(STDERR, 'error: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @return list<array{alias: string, display: string}> declarations in file order
 */
function parseDeclarations(string $content): array
{
    if (preg_match_all(DECLARATION_RE, $content, $matches, PREG_SET_ORDER) === false) {
        fail('failed to scan declarations');
    }

    $declarations = [];
    foreach ($matches as $match) {
        $declarations[] = ['display' => $match[1], 'alias' => $match[2]];
    }

    return $declarations;
}

/**
 * Package name/alias identifiers, so `package Structure as Structure` is
 * anonymized consistently with the classes it wraps.
 *
 * @return list<string>
 */
function parsePackageNames(string $content): array
{
    if (preg_match_all(PACKAGE_RE, $content, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
        fail('failed to scan packages');
    }

    $names = [];
    foreach ($matches as $match) {
        $name = $match[1] ?? $match[2];
        if ($name !== null) {
            $names[] = $name;
        }
        if (($match[3] ?? null) !== null) {
            $names[] = $match[3];
        }
    }

    return array_values(array_unique($names));
}

/**
 * @return list<string> CamelCase tokens
 */
function tokenize(string $name): array
{
    if (preg_match_all(TOKEN_RE, $name, $matches) === false) {
        return [];
    }

    return $matches[0];
}

/**
 * @param list<array{alias: string, display: string}> $declarations
 * @param list<string>                                $packageNames
 *
 * @return array<string, string> real token => pseudonym
 */
function buildTokenMap(array $declarations, array $packageNames): array
{
    /** @var array<string, true> $tokens */
    $tokens = [];
    foreach ($declarations as $declaration) {
        foreach (tokenize($declaration['alias']) as $token) {
            $tokens[$token] = true;
        }
        foreach (tokenize($declaration['display']) as $token) {
            $tokens[$token] = true;
        }
    }
    foreach ($packageNames as $name) {
        foreach (tokenize($name) as $token) {
            $tokens[$token] = true;
        }
    }

    $width = max(3, strlen((string) count($tokens)));
    $map = [];
    $index = 0;
    foreach (array_keys($tokens) as $token) {
        $index++;
        $map[$token] = 'Tok' . str_pad((string) $index, $width, '0', STR_PAD_LEFT);
    }

    return $map;
}

/**
 * Recomposes a name by replacing each token with its pseudonym, keeping every
 * separator (underscores, etc.) intact.
 *
 * @param array<string, string> $tokenMap
 */
function recompose(string $name, array $tokenMap): string
{
    $result = preg_replace_callback(
        TOKEN_RE,
        static fn (array $m): string => $tokenMap[$m[0]] ?? $m[0],
        $name,
    );

    return $result ?? $name;
}

/**
 * @param list<array{alias: string, display: string}> $declarations
 * @param list<string>                                $packageNames
 * @param array<string, string>                       $tokenMap
 *
 * @return array<string, string> original identifier => anonymized identifier
 */
function buildNameMap(array $declarations, array $packageNames, array $tokenMap): array
{
    $map = [];
    foreach ($declarations as $declaration) {
        $map[$declaration['alias']] = recompose($declaration['alias'], $tokenMap);
        if ($declaration['display'] !== $declaration['alias']) {
            $map[$declaration['display']] = recompose($declaration['display'], $tokenMap);
        }
    }
    foreach ($packageNames as $name) {
        $map[$name] = recompose($name, $tokenMap);
    }

    return $map;
}

/**
 * @param array<string, string> $nameMap
 */
function anonymize(string $content, array $nameMap): string
{
    if ($nameMap === []) {
        return $content;
    }

    // Longest identifiers first so a short name never pre-empts a longer one.
    $keys = array_keys($nameMap);
    usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    $pattern = '/\b(' . implode('|', array_map(
        static fn (string $k): string => preg_quote($k, '/'),
        $keys,
    )) . ')\b/';

    $result = preg_replace_callback($pattern, static fn (array $m): string => $nameMap[$m[1]], $content);
    if ($result === null) {
        fail('name replacement failed');
    }

    return $result;
}

/**
 * Regenerates every class body with generic members derived from the node's
 * outgoing dependency (`..>`) relations, erasing real field/method/parameter
 * names. Relations are left untouched, so the graph is preserved.
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
 * Structural signature of a list of names: each name becomes the sequence of its
 * token identities (ids assigned by first appearance). Equal signatures mean the
 * token counts and the token-sharing pattern are identical.
 *
 * @param list<string> $names
 *
 * @return list<string>
 */
function tokenSignature(array $names): array
{
    /** @var array<string, int> $ids */
    $ids = [];
    $next = 0;
    $signatures = [];
    foreach ($names as $name) {
        $sequence = [];
        foreach (tokenize($name) as $token) {
            if (!isset($ids[$token])) {
                $ids[$token] = $next++;
            }
            $sequence[] = $ids[$token];
        }
        $signatures[] = implode('-', $sequence);
    }

    return $signatures;
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

$declarations = parseDeclarations($content);
$packageNames = parsePackageNames($content);
$tokenMap = buildTokenMap($declarations, $packageNames);
$nameMap = buildNameMap($declarations, $packageNames, $tokenMap);

$output = anonymize($content, $nameMap);
if ($scrubMembers) {
    $output = scrubMembers($output);
}

// Invariant 1: identical sorted degree distributions.
$before = degreeDistribution($content);
$after = degreeDistribution($output);
if ($before !== $after) {
    fwrite(STDERR, sprintf(
        "verification FAILED: degree distributions differ (edges %d vs %d)\n",
        $before['edges'],
        $after['edges'],
    ));
    exit(1);
}

// Invariant 2: tokenisation structure preserved (token count + sharing pattern).
$oldAliases = array_map(static fn (array $d): string => $d['alias'], $declarations);
$newAliases = array_map(static fn (array $d): string => $d['alias'], parseDeclarations($output));
if (tokenSignature($oldAliases) !== tokenSignature($newAliases)) {
    fwrite(STDERR, "verification FAILED: token structure changed\n");
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
    "ok: %d names anonymized over %d distinct tokens, %d relations preserved\n",
    count($declarations),
    count($tokenMap),
    $before['edges'],
));
exit(0);
