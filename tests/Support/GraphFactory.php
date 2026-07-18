<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Support;

use PumlSplitter\Graph\Graph;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;

/**
 * Builds small graphs/documents from an edge list for the graph unit tests.
 */
final class GraphFactory
{
    /**
     * @param list<array{0: string, 1: string}> $edges     directed edges (source, target)
     * @param list<string>                       $isolated  extra declared nodes with no edges
     */
    public static function fromEdges(array $edges, array $isolated = []): Graph
    {
        return Graph::fromDocument(self::document($edges, $isolated));
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                       $isolated
     */
    public static function document(array $edges, array $isolated = []): Document
    {
        $aliases = $isolated;
        foreach ($edges as [$source, $target]) {
            $aliases[] = $source;
            $aliases[] = $target;
        }

        $classes = [];
        foreach (array_unique($aliases) as $alias) {
            $classes[$alias] = new ClassDeclaration($alias, $alias, ClassKind::Clazz, null);
        }

        $relations = [];
        foreach ($edges as [$source, $target]) {
            $relations[] = new Relation($source, '..>', $target, null, sprintf('%s ..> %s', $source, $target));
        }

        return new Document('@startuml', [], $classes, $relations);
    }
}
