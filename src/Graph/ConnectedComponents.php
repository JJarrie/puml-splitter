<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Undirected connected components of the graph, computed over the nodes that
 * remain once the hubs have been excluded. Fully deterministic: start nodes are
 * visited in alias order, each component is sorted, and components are ordered by
 * their smallest alias.
 */
final class ConnectedComponents
{
    /**
     * @param list<string> $excluded aliases to omit (hubs)
     *
     * @return list<list<string>> each component as a sorted list of aliases
     */
    public function compute(Graph $graph, array $excluded = []): array
    {
        $excludedSet = array_fill_keys($excluded, true);

        /** @var array<string, true> $seen */
        $seen = [];
        $components = [];

        foreach ($graph->nodes() as $start) {
            if (isset($excludedSet[$start]) || isset($seen[$start])) {
                continue;
            }

            $component = [];
            $stack = [$start];
            $seen[$start] = true;

            while ($stack !== []) {
                $node = array_pop($stack);
                $component[] = $node;

                foreach ($graph->neighbours($node) as $neighbour) {
                    if (isset($excludedSet[$neighbour]) || isset($seen[$neighbour])) {
                        continue;
                    }
                    $seen[$neighbour] = true;
                    $stack[] = $neighbour;
                }
            }

            sort($component, SORT_STRING);
            $components[] = $component;
        }

        // Order components deterministically by their smallest alias.
        usort($components, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $components;
    }
}
