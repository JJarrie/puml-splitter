<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Output\ClusterView;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Parser;

#[CoversClass(OverviewPumlGenerator::class)]
final class OverviewPumlGeneratorTest extends TestCase
{
    private function overview(): string
    {
        $classes = [];
        foreach (['A1', 'A2', 'B1', 'B2'] as $alias) {
            $classes[$alias] = new ClassDeclaration($alias, $alias, ClassKind::Clazz, null);
        }
        $document = new Document('@startuml', [], $classes, [
            new Relation('A1', '..>', 'A2', null, 'A1 ..> A2'), // internal to alpha
            new Relation('A1', '..>', 'B1', null, 'A1 ..> B1'),
            new Relation('A2', '..>', 'B1', null, 'A2 ..> B1'),
        ]);

        return (new OverviewPumlGenerator())->generate(
            [new ClusterView('alpha', 'Alpha', ['A1', 'A2']), new ClusterView('beta', 'Beta', ['B1', 'B2'])],
            ['A1' => 'alpha', 'A2' => 'alpha', 'B1' => 'beta', 'B2' => 'beta'],
            $document,
            [],
        );
    }

    public function testEmitsOnePackagePerClusterWithSize(): void
    {
        $overview = $this->overview();

        self::assertStringContainsString('package "Alpha (2)" as alpha {', $overview);
        self::assertStringContainsString('package "Beta (2)" as beta {', $overview);
    }

    public function testAggregatesInterClusterEdgesWithThickness(): void
    {
        // Two alpha→beta edges (the internal one is ignored); count 2 is the max,
        // so it maps to the thickest stroke.
        self::assertStringContainsString('alpha -[thickness=4]-> beta : 2', $this->overview());
    }

    public function testRoundTripsWithoutWarning(): void
    {
        $parser = new Parser();
        $parser->parse($this->overview());

        self::assertSame([], $parser->warnings());
    }

    public function testSanitizesQuotesInClusterName(): void
    {
        $document = new Document('@startuml', [], [
            'A1' => new ClassDeclaration('A1', 'A1', ClassKind::Clazz, null),
        ], []);

        $overview = (new OverviewPumlGenerator())->generate(
            [new ClusterView('foo', 'Foo"Bar', ['A1'])],
            ['A1' => 'foo'],
            $document,
            [],
        );

        self::assertStringContainsString('package "Foo\'Bar (1)" as foo {', $overview);

        // And the result still round-trips cleanly.
        $parser = new Parser();
        $parser->parse($overview);
        self::assertSame([], $parser->warnings());
    }
}
