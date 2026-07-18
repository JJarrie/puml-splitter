<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;

#[CoversClass(Graph::class)]
final class GraphTest extends TestCase
{
    public function testCountsDegreesAndIncludesUndeclaredTargets(): void
    {
        $graph = Graph::fromDocument($this->document());

        // "Ghost" is only referenced by a relation, never declared.
        self::assertSame(6, $graph->nodeCount());
        self::assertSame(
            ['Ann', 'Bob', 'Cyd', 'Dan', 'Ghost', 'Zed'],
            $graph->nodes(),
        );

        self::assertSame(2, $graph->inDegree('Zed'));
        self::assertSame(1, $graph->inDegree('Ghost'));
        self::assertSame(0, $graph->inDegree('Cyd'));
        self::assertSame(2, $graph->outDegree('Ann'));
        self::assertSame(0, $graph->outDegree('Zed'));
    }

    public function testTopByInDegreeBreaksTiesAlphabetically(): void
    {
        $graph = Graph::fromDocument($this->document());

        self::assertSame(
            [
                ['alias' => 'Zed', 'degree' => 2],
                ['alias' => 'Ann', 'degree' => 1],
                ['alias' => 'Bob', 'degree' => 1],
                ['alias' => 'Ghost', 'degree' => 1],
            ],
            $graph->topByInDegree(4),
        );
    }

    public function testTopByOutDegreeIsDeterministic(): void
    {
        $graph = Graph::fromDocument($this->document());

        self::assertSame(
            [
                ['alias' => 'Ann', 'degree' => 2],
                ['alias' => 'Bob', 'degree' => 1],
                ['alias' => 'Cyd', 'degree' => 1],
            ],
            $graph->topByOutDegree(3),
        );
    }

    private function document(): Document
    {
        $classes = [];
        foreach (['Ann', 'Bob', 'Cyd', 'Dan', 'Zed'] as $alias) {
            $classes[$alias] = new ClassDeclaration($alias, $alias, ClassKind::Clazz, null);
        }

        $relations = [
            new Relation('Ann', '..>', 'Zed', null, 'Ann ..> Zed'),
            new Relation('Bob', '..>', 'Zed', null, 'Bob ..> Zed'),
            new Relation('Cyd', '..>', 'Ann', null, 'Cyd ..> Ann'),
            new Relation('Dan', '..>', 'Bob', null, 'Dan ..> Bob'),
            new Relation('Ann', '..>', 'Ghost', null, 'Ann ..> Ghost'),
        ];

        return new Document('@startuml', [], $classes, $relations);
    }
}
