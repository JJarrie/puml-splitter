<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Parses and structurally validates a `--map=FILE` (plan §6ter). Pure file/JSON
 * validation only — no {@see Graph}, so it's testable without one; alias
 * existence against the actual graph is a separate concern (see
 * {@see MapPartitioner}), since that requires the parsed graph this loader
 * deliberately doesn't depend on.
 *
 * Format: `{ "clusters": { "name": ["Alias1", "Alias2"], ... }, "fallback": "auto" }`.
 * `fallback` is optional, defaulting to "auto".
 */
final class MapFileLoader
{
    private const VALID_FALLBACKS = [MapFile::FALLBACK_AUTO, MapFile::FALLBACK_MISC, MapFile::FALLBACK_ERROR];

    public function load(string $path): MapLoadResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return $this->fatal(sprintf('Cannot read map file: %s', $path));
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return $this->fatal(sprintf('Cannot read map file: %s', $path));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fatal(sprintf('Invalid JSON in map file %s: %s', $path, json_last_error_msg()));
        }

        // array_is_list([]) is true, so the empty-array check must come first:
        // {} and [] both decode to [], but only a non-empty list actually
        // proves the JSON was an array rather than an (empty) object.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->fatal(sprintf('Map file %s must be a JSON object with a "clusters" key.', $path));
        }

        if (!isset($data['clusters']) || !is_array($data['clusters'])) {
            return $this->fatal(sprintf('Map file %s must have a "clusters" object mapping cluster names to alias lists.', $path));
        }
        $clustersRaw = $data['clusters'];
        if ($clustersRaw !== [] && array_is_list($clustersRaw)) {
            return $this->fatal(sprintf('Map file %s must have a "clusters" object mapping cluster names to alias lists.', $path));
        }

        $fallbackRaw = $data['fallback'] ?? MapFile::FALLBACK_AUTO;
        if (!is_string($fallbackRaw) || !in_array($fallbackRaw, self::VALID_FALLBACKS, true)) {
            return $this->fatal(sprintf(
                'Map file %s has an invalid "fallback" value%s (expected %s).',
                $path,
                is_string($fallbackRaw) ? ": \"{$fallbackRaw}\"" : '',
                implode(', ', self::VALID_FALLBACKS),
            ));
        }

        $clusters = [];
        foreach ($clustersRaw as $name => $aliasesRaw) {
            if (!is_string($name) || $name === '') {
                return $this->fatal(sprintf('Map file %s has a non-string or empty cluster name.', $path));
            }
            // "noms de clusters normalisés en slugs" (plan §6ter): the name
            // itself is kept verbatim (see MapEmitter's docblock for why —
            // round-trip fidelity of the displayed name), but one that the
            // §7 slug rule would collapse to nothing is genuinely unusable
            // and rejected outright, same bar declaration-name emptiness
            // already gets below.
            if (ClusterSlug::of($name) === 'cluster' && $name !== 'cluster') {
                return $this->fatal(sprintf(
                    'Map file %s: cluster name "%s" normalizes to an empty slug and can\'t be used.',
                    $path,
                    $name,
                ));
            }
            if (!is_array($aliasesRaw) || !array_is_list($aliasesRaw)) {
                return $this->fatal(sprintf('Map file %s: cluster "%s" must be a list of alias strings.', $path, $name));
            }

            $aliases = [];
            foreach ($aliasesRaw as $alias) {
                if (!is_string($alias) || $alias === '') {
                    return $this->fatal(sprintf('Map file %s: cluster "%s" contains a non-string or empty alias.', $path, $name));
                }
                $aliases[] = $alias;
            }

            $clusters[$name] = $aliases;
        }

        $repeated = $this->duplicateWithinACluster($clusters);
        if ($repeated !== []) {
            return $this->fatal(sprintf(
                'Map file %s repeats the same alias within one cluster: %s.',
                $path,
                implode(', ', $repeated),
            ));
        }

        $conflicting = $this->duplicateAcrossClusters($clusters);
        if ($conflicting !== []) {
            return $this->fatal(sprintf(
                'Map file %s assigns the same alias to more than one cluster: %s.',
                $path,
                implode(', ', $conflicting),
            ));
        }

        return new MapLoadResult(new MapFile($clusters, $fallbackRaw), null);
    }

    /**
     * @param array<string, list<string>> $clusters
     *
     * @return list<string> aliases appearing more than once within the SAME cluster, sorted
     */
    private function duplicateWithinACluster(array $clusters): array
    {
        $repeated = [];
        foreach ($clusters as $aliases) {
            $seen = [];
            foreach ($aliases as $alias) {
                if (isset($seen[$alias])) {
                    $repeated[$alias] = true;
                }
                $seen[$alias] = true;
            }
        }

        return $this->sorted($repeated);
    }

    /**
     * @param array<string, list<string>> $clusters
     *
     * @return list<string> aliases claimed by more than one DIFFERENT cluster, sorted
     */
    private function duplicateAcrossClusters(array $clusters): array
    {
        $owner = [];
        $conflicting = [];
        foreach ($clusters as $name => $aliases) {
            foreach (array_unique($aliases) as $alias) {
                if (isset($owner[$alias]) && $owner[$alias] !== $name) {
                    $conflicting[$alias] = true;
                } else {
                    $owner[$alias] = $name;
                }
            }
        }

        return $this->sorted($conflicting);
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private function sorted(array $set): array
    {
        $list = array_keys($set);
        sort($list, SORT_STRING);

        return $list;
    }

    private function fatal(string $message): MapLoadResult
    {
        return new MapLoadResult(null, $message);
    }
}
