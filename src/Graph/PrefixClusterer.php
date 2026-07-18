<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Groups classes by the leading token(s) of their short (display) name. Names are
 * split into CamelCase tokens; the primary key is the first token, and any group
 * larger than max-size is subdivided by its first two tokens (plan §6.3).
 *
 * Useful for POPO generated from an XSD, where names share meaningful prefixes.
 */
final class PrefixClusterer implements Clusterer
{
    /**
     * @param array<string, string> $shortNames alias => quoted short name
     */
    public function __construct(
        private readonly array $shortNames,
        private readonly int $maxSize,
    ) {
    }

    public function cluster(array $members): array
    {
        $byFirst = $this->groupBy($members, 1);

        $clusters = [];
        foreach ($this->sortedKeys($byFirst) as $key) {
            $group = $byFirst[$key];

            if (count($group) <= $this->maxSize) {
                $clusters[] = new Cluster($key, $group);
                continue;
            }

            // Oversized first-token group: subdivide by the first two tokens.
            $bySecond = $this->groupBy($group, 2);

            // If every two-token bucket is a singleton the names share no
            // meaningful second-level prefix: the group is not cleanly splittable,
            // so keep it whole rather than exploding into singletons.
            if (count($bySecond) >= count($group)) {
                $clusters[] = new Cluster($key, $group);
                continue;
            }

            foreach ($this->sortedKeys($bySecond) as $subKey) {
                $clusters[] = new Cluster($subKey, $bySecond[$subKey]);
            }
        }

        usort($clusters, static fn (Cluster $a, Cluster $b): int => strcmp($a->name, $b->name) ?: strcmp($a->members[0], $b->members[0]));

        return $clusters;
    }

    /**
     * @param list<string> $members
     *
     * @return array<string, list<string>>
     */
    private function groupBy(array $members, int $depth): array
    {
        $sorted = $members;
        sort($sorted, SORT_STRING);

        $groups = [];
        foreach ($sorted as $alias) {
            $groups[$this->prefixKey($alias, $depth)][] = $alias;
        }

        return $groups;
    }

    private function prefixKey(string $alias, int $depth): string
    {
        $tokens = $this->tokenize($this->shortNames[$alias] ?? $alias);
        if ($tokens === []) {
            return $alias;
        }

        return implode('', array_slice($tokens, 0, $depth));
    }

    /**
     * @return list<string> CamelCase tokens
     */
    private function tokenize(string $name): array
    {
        // Digits stay attached to their letter run, so a pseudonym like "Tok001"
        // (from scripts/anonymize-puml.php) is a single token — otherwise every
        // anonymized name would share the leading "Tok" and grouping would
        // degenerate.
        if (preg_match_all('/[A-Z]+(?![a-z])|[A-Z][a-z0-9]*|[a-z][a-z0-9]*|[0-9]+/', $name, $matches) === false) {
            return [];
        }

        return $matches[0];
    }

    /**
     * @param array<string, list<string>> $groups
     *
     * @return list<string>
     */
    private function sortedKeys(array $groups): array
    {
        $keys = array_keys($groups);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
