<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

use PumlSplitter\Puml\Model\Document;

/**
 * A simple directed graph keyed by PlantUML alias.
 *
 * Adjacency lists suffice at this scale (~150 nodes). Nodes are the union of
 * declared aliases and any alias referenced by a relation (edges to undeclared
 * targets are tolerated). All rankings are deterministic: ties break on the
 * alias, alphabetically.
 */
final class Graph
{
    /** @var array<string, int> in-degree keyed by alias */
    private array $inDegree = [];

    /** @var array<string, int> out-degree keyed by alias */
    private array $outDegree = [];

    /** @var array<string, array<string, true>> undirected adjacency sets keyed by alias */
    private array $adjacency = [];

    public static function fromDocument(Document $document): self
    {
        $graph = new self();

        foreach (array_keys($document->classes()) as $alias) {
            $graph->touch($alias);
        }

        foreach ($document->relations() as $relation) {
            $graph->touch($relation->source);
            $graph->touch($relation->target);
            $graph->outDegree[$relation->source]++;
            $graph->inDegree[$relation->target]++;

            if ($relation->source !== $relation->target) {
                $graph->adjacency[$relation->source][$relation->target] = true;
                $graph->adjacency[$relation->target][$relation->source] = true;
            }
        }

        return $graph;
    }

    public function hasNode(string $alias): bool
    {
        return isset($this->inDegree[$alias]);
    }

    /**
     * Undirected neighbours of a node, sorted alphabetically.
     *
     * @return list<string>
     */
    public function neighbours(string $alias): array
    {
        $neighbours = array_keys($this->adjacency[$alias] ?? []);
        sort($neighbours, SORT_STRING);

        return $neighbours;
    }

    /**
     * @return list<string> node aliases, sorted alphabetically
     */
    public function nodes(): array
    {
        $nodes = array_keys($this->inDegree);
        sort($nodes, SORT_STRING);

        return $nodes;
    }

    public function nodeCount(): int
    {
        return count($this->inDegree);
    }

    public function inDegree(string $alias): int
    {
        return $this->inDegree[$alias] ?? 0;
    }

    public function outDegree(string $alias): int
    {
        return $this->outDegree[$alias] ?? 0;
    }

    /**
     * @return list<array{alias: string, degree: int}> top-$n nodes by in-degree
     */
    public function topByInDegree(int $n): array
    {
        return $this->topBy($this->inDegree, $n);
    }

    /**
     * @return list<array{alias: string, degree: int}> top-$n nodes by out-degree
     */
    public function topByOutDegree(int $n): array
    {
        return $this->topBy($this->outDegree, $n);
    }

    private function touch(string $alias): void
    {
        $this->inDegree[$alias] ??= 0;
        $this->outDegree[$alias] ??= 0;
    }

    /**
     * @param array<string, int> $degrees
     *
     * @return list<array{alias: string, degree: int}>
     */
    private function topBy(array $degrees, int $n): array
    {
        $aliases = array_keys($degrees);

        // Descending degree; ties broken by alias, alphabetically.
        usort(
            $aliases,
            static fn (string $a, string $b): int => ($degrees[$b] <=> $degrees[$a]) ?: strcmp($a, $b),
        );

        $top = [];
        foreach (array_slice($aliases, 0, max(0, $n)) as $alias) {
            $top[] = ['alias' => $alias, 'degree' => $degrees[$alias]];
        }

        return $top;
    }
}
