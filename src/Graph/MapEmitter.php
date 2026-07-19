<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Exports a computed {@see Partition} as a `--map=FILE`-compatible JSON file
 * (plan §6ter), usable with any strategy: run `auto` → `--emit-map` → hand-edit
 * the debatable 5% → subsequent runs as `--strategy=map`.
 *
 * Cluster names are emitted exactly as {@see Cluster::$name} — no slugification
 * here; the existing output-generation slug rule (plan §7) already derives file
 * names from that same field for every strategy, so re-slugifying at emission
 * would only risk the round-trip changing a cluster's displayed name.
 * {@see Partition::$clusters} never contains hubs or "external" boundary stubs
 * (those are hub-policy / per-output-file concepts, not part of the graph
 * partition), so no filtering is needed to satisfy that plan requirement.
 *
 * Two clusters can legitimately share a `name` (e.g. a mapped cluster named
 * "misc" plus a `fallback=misc` cluster using that same literal name) —
 * Partition::$clusters is a list, not keyed by name. Emitting them under the
 * same JSON key would silently drop one, so a repeat gets the same
 * collision-suffix treatment as file-name slugs (plan §7) instead.
 */
final class MapEmitter
{
    public function emit(Partition $partition): string
    {
        /** @var array<string, true> $used */
        $used = [];
        $clusters = [];
        foreach (Cluster::sortAll($partition->clusters) as $cluster) {
            $key = ClusterSlug::unique($cluster->name, $used);
            $clusters[$key] = $cluster->members;
        }
        ksort($clusters, SORT_STRING);

        $data = [
            'clusters' => (object) $clusters,
            'fallback' => MapFile::FALLBACK_AUTO,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Partition data is always plain strings/arrays; this is
            // unreachable in practice but keeps the return type honest.
            throw new \RuntimeException('Failed to encode map as JSON.');
        }

        return $json . "\n";
    }
}
