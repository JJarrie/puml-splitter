<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * The file-name-safe slug rule (plan §7): lowercase, alnum-and-underscore
 * only, collision-suffixed. Shared by output generation (deriving
 * `cluster-<slug>.puml`) and `--strategy=map` validation ("noms de clusters
 * normalisés en slugs (mêmes règles que le nommage §7)", plan §6ter) so both
 * apply the exact same rule instead of two copies that could drift apart.
 */
final class ClusterSlug
{
    public static function of(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');

        return $slug === '' ? 'cluster' : $slug;
    }

    /**
     * @param array<string, true> $used
     */
    public static function unique(string $base, array &$used): string
    {
        $candidate = $base;
        $suffix = 1;
        while (isset($used[$candidate])) {
            $suffix++;
            $candidate = $base . '_' . $suffix;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
