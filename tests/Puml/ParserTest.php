<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Puml;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;

#[CoversClass(Parser::class)]
#[CoversClass(Document::class)]
final class ParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testCapturesStartLineAndHeaders(): void
    {
        $document = $this->parseFixture('small.puml');

        self::assertSame('@startuml class-diagram', $document->startLine);
        self::assertSame(
            ['  skinparam classAttributeIconSize 0', '  hide empty members'],
            $document->headerLines,
        );
    }

    public function testParsesEveryDeclarationKind(): void
    {
        $classes = $this->parseFixture('small.puml')->classes();

        self::assertSame(
            ['Product', 'Name', 'Price', 'Base', 'Repository', 'Suit'],
            array_keys($classes),
        );
        self::assertSame(ClassKind::Clazz, $classes['Product']->kind);
        self::assertSame(ClassKind::AbstractClass, $classes['Base']->kind);
        self::assertSame(ClassKind::Interface_, $classes['Repository']->kind);
        self::assertSame(ClassKind::Enum, $classes['Suit']->kind);
    }

    public function testKeepsQuotedNameSeparateFromAlias(): void
    {
        $product = $this->parseFixture('small.puml')->getClass('Product');

        self::assertNotNull($product);
        self::assertSame('Product', $product->alias);
        self::assertSame('Product', $product->name);
    }

    public function testPreservesMultiLineBodyByteIdentically(): void
    {
        $product = $this->parseFixture('small.puml')->getClass('Product');

        self::assertNotNull($product);
        self::assertSame(['    -name : Name', '    -price : Price'], $product->bodyLines);
    }

    public function testDistinguishesEmptyBodyFromNoBody(): void
    {
        $document = $this->parseFixture('small.puml');

        $price = $document->getClass('Price');
        self::assertNotNull($price);
        self::assertTrue($price->hasBody());
        self::assertSame([], $price->bodyLines);

        $base = $document->getClass('Base');
        self::assertNotNull($base);
        self::assertFalse($base->hasBody());
        self::assertNull($base->bodyLines);
    }

    public function testParsesRelationWithoutLabel(): void
    {
        $relations = $this->parseFixture('small.puml')->relations();

        $relation = $relations[0];
        self::assertSame('Product', $relation->source);
        self::assertSame('..>', $relation->arrow);
        self::assertSame('Name', $relation->target);
        self::assertNull($relation->label);
    }

    public function testParsesRelationWithLabel(): void
    {
        $relations = $this->parseFixture('small.puml')->relations();

        $relation = $relations[1];
        self::assertSame('Price', $relation->target);
        self::assertSame('owns', $relation->label);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function inheritanceArrowProvider(): iterable
    {
        yield 'extends' => [2, '<|--'];
        yield 'implements' => [3, '<|..'];
    }

    #[DataProvider('inheritanceArrowProvider')]
    public function testInheritanceArrowsAreEdges(int $index, string $arrow): void
    {
        $relations = $this->parseFixture('small.puml')->relations();

        self::assertSame($arrow, $relations[$index]->arrow);
    }

    /**
     * Bracket-styled arrows (plan §7bis edge colouring/thickness) are exactly
     * the forms {@see \PumlSplitter\Puml\Writer::styleArrow()} emits — every
     * one of the 6 arrow types, both bare and styled, must round-trip.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function styledArrowProvider(): iterable
    {
        yield 'dotted dependency' => ['..>', '.[#3388CC].>'];
        yield 'solid dependency' => ['-->', '-[#3388CC]->'];
        yield 'aggregation' => ['o--', 'o-[#3388CC]-'];
        yield 'composition' => ['*--', '*-[#3388CC]-'];
        yield 'inheritance' => ['<|--', '<|-[thickness=2]--'];
        yield 'implementation' => ['<|..', '<|.[thickness=2]..'];
    }

    #[DataProvider('styledArrowProvider')]
    public function testBareAndStyledArrowsBothParseToTheSameCanonicalArrow(string $bare, string $styled): void
    {
        $parseArrow = function (string $arrow): string {
            $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A {$arrow} B\n@enduml\n";
            $parser = new Parser();
            $document = $parser->parse($content);
            self::assertSame([], $parser->warnings(), "unexpected warnings for arrow: {$arrow}");

            return $document->relations()[0]->arrow;
        };

        self::assertSame($bare, $parseArrow($bare));
        self::assertSame($styled, $parseArrow($styled));
    }

    /**
     * A malformed arrow that this tool never emits (extra trunk characters
     * with no bracket) must still fall through to the unrecognized-line
     * warning path, not be silently accepted as a relation.
     */
    public function testMalformedInheritanceArrowIsUnrecognized(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A <|--- B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $document->relations());
        self::assertNotSame([], $parser->warnings());
    }

    public function testParsesRelationWithSourceMultiplicityOnly(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A \"1\" ..> B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $parser->warnings());
        $relation = $document->relations()[0];
        self::assertSame('1', $relation->sourceMultiplicity);
        self::assertNull($relation->targetMultiplicity);
    }

    public function testParsesRelationWithTargetMultiplicityOnly(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A ..> \"*\" B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $parser->warnings());
        $relation = $document->relations()[0];
        self::assertNull($relation->sourceMultiplicity);
        self::assertSame('*', $relation->targetMultiplicity);
    }

    public function testParsesRelationWithMultiplicityOnBothSides(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A \"1\" ..> \"*\" B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $parser->warnings());
        $relation = $document->relations()[0];
        self::assertSame('A', $relation->source);
        self::assertSame('..>', $relation->arrow);
        self::assertSame('B', $relation->target);
        self::assertSame('1', $relation->sourceMultiplicity);
        self::assertSame('*', $relation->targetMultiplicity);
    }

    public function testParsesRelationWithMultiplicityAndLabel(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A \"1\" ..> \"*\" B : owns\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $parser->warnings());
        $relation = $document->relations()[0];
        self::assertSame('owns', $relation->label);
        self::assertSame('1', $relation->sourceMultiplicity);
        self::assertSame('*', $relation->targetMultiplicity);
    }

    public function testParsesRelationWithMultiplicityAndStyledArrow(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A \"1\" .[#3388CC].> \"*\" B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame([], $parser->warnings());
        $relation = $document->relations()[0];
        self::assertSame('.[#3388CC].>', $relation->arrow);
        self::assertSame('1', $relation->sourceMultiplicity);
        self::assertSame('*', $relation->targetMultiplicity);
    }

    /**
     * Round-trip invariant (plan §11): a parsed multiplicity-annotated
     * relation, re-emitted by the Writer, must re-parse without warnings and
     * with the same multiplicities — not just be silently accepted once.
     */
    public function testMultiplicityRelationRoundTripsWithoutWarnings(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\n  A \"1\" ..> \"*\" B : owns\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);
        $original = $document->relations()[0];

        $emitted = (new Writer())->relation($original);

        $reparsed = new Parser();
        $reparsedDocument = $reparsed->parse("@startuml x\nclass \"A\" as A\nclass \"B\" as B\n{$emitted}\n@enduml\n");

        self::assertSame([], $reparsed->warnings());
        $roundTripped = $reparsedDocument->relations()[0];
        self::assertSame($original->source, $roundTripped->source);
        self::assertSame($original->arrow, $roundTripped->arrow);
        self::assertSame($original->target, $roundTripped->target);
        self::assertSame($original->label, $roundTripped->label);
        self::assertSame($original->sourceMultiplicity, $roundTripped->sourceMultiplicity);
        self::assertSame($original->targetMultiplicity, $roundTripped->targetMultiplicity);
    }

    public function testUnterminatedLegendBlockWarnsAndDoesNotSwallowEnduml(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nlegend bottom\n  no endlegend here\nclass \"B\" as B\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        // The content between "legend" and @enduml is lost either way (it's
        // presentational, regenerated fresh — see Parser::parse()), but the
        // parser must say so rather than silently drop it, and @enduml must
        // still terminate the diagram normally.
        self::assertArrayHasKey('A', $document->classes());
        self::assertArrayNotHasKey('B', $document->classes());
        self::assertNotSame([], $parser->warnings());
        self::assertStringContainsString('Unterminated legend', $parser->warnings()[0]);
    }

    public function testTerminatedLegendBlockIsSkippedWithoutWarning(): void
    {
        $content = "@startuml x\nclass \"A\" as A\nclass \"B\" as B\nlegend bottom\n  some text\nendlegend\n@enduml\n";
        $parser = new Parser();
        $document = $parser->parse($content);

        self::assertSame(['A', 'B'], array_keys($document->classes()));
        self::assertSame([], $parser->warnings());
    }

    public function testFlattensPackagesAndKeepsPackageMetadata(): void
    {
        $document = $this->parseFixture('packages.puml');

        self::assertSame(
            ['Order', 'Shared_ElementSequence', 'Shared_JsonNormalizer'],
            array_keys($document->classes()),
        );

        self::assertNull($document->getClass('Order')?->package);
        self::assertSame('Shared', $document->getClass('Shared_ElementSequence')?->package);
        self::assertSame('Shared', $document->getClass('Shared_JsonNormalizer')?->package);

        // Relations inside/across the package are preserved.
        self::assertCount(2, $document->relations());
    }

    public function testInlineEmptyBodyIsParsed(): void
    {
        $document = (new Parser())->parse(
            "@startuml\nclass \"Widget\" as Widget {}\n@enduml\n",
        );

        $widget = $document->getClass('Widget');
        self::assertNotNull($widget);
        self::assertTrue($widget->hasBody());
        self::assertSame([], $widget->bodyLines);
    }

    public function testInlineBodyContentIsCaptured(): void
    {
        $document = (new Parser())->parse(
            "@startuml\nclass \"Widget\" as Widget { +size : int }\n@enduml\n",
        );

        self::assertSame(['+size : int'], $document->getClass('Widget')?->bodyLines);
    }

    public function testDuplicateAliasIsIgnoredWithWarning(): void
    {
        $parser = new Parser();
        $document = $parser->parse(
            "@startuml\nclass \"First\" as X {\n  +a : int\n}\nclass \"Second\" as X {\n  +b : int\n}\n@enduml\n",
        );

        // The first declaration wins; the duplicate is dropped, not silently merged.
        self::assertCount(1, $document->classes());
        self::assertSame('First', $document->getClass('X')?->name);
        self::assertNotEmpty(array_filter(
            $parser->warnings(),
            static fn (string $w): bool => str_contains($w, 'Duplicate alias'),
        ));
    }

    public function testParsesFragmentWithoutStartMarker(): void
    {
        $parser = new Parser();
        $document = $parser->parse("class \"A\" as A {\n  +x : int\n}\nclass \"B\" as B\nA ..> B\n");

        self::assertCount(2, $document->classes());
        self::assertCount(1, $document->relations());
        self::assertSame([], $parser->warnings());
    }

    public function testUnknownLineIsPassthroughWithWarningAndNoCrash(): void
    {
        $parser = new Parser();
        $document = $parser->parse($this->readFixture('unknown-line.puml'));

        self::assertCount(2, $document->classes());
        self::assertCount(1, $document->relations());

        self::assertCount(1, $document->passthrough);
        self::assertSame('this line makes no sense at all', trim($document->passthrough[0]['text']));

        self::assertNotEmpty($parser->warnings());
        self::assertStringContainsString('Unrecognized line', $parser->warnings()[0]);
    }

    public function testParsesRealLargeFixture(): void
    {
        $parser = new Parser();
        $document = $parser->parse($this->readFixture('very-large.puml'));

        self::assertSame(156, $document->classCount());
        self::assertSame(290, $document->relationCount());
        self::assertSame([], $parser->warnings());
        self::assertSame([], $document->passthrough);

        // The (anonymized) package block is flattened: its 3 classes are tagged
        // with the same non-null package name.
        $packages = [];
        foreach ($document->classes() as $class) {
            if ($class->package !== null) {
                $packages[$class->package] = ($packages[$class->package] ?? 0) + 1;
            }
        }
        self::assertCount(1, $packages);
        self::assertSame(3, array_values($packages)[0]);
    }

    private function parseFixture(string $name): Document
    {
        return (new Parser())->parse($this->readFixture($name));
    }

    private function readFixture(string $name): string
    {
        $content = file_get_contents(self::FIXTURES . '/' . $name);
        self::assertIsString($content);

        return $content;
    }
}
