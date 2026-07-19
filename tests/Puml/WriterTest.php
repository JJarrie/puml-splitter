<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Puml;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Writer;

#[CoversClass(Writer::class)]
final class WriterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function arrowProvider(): iterable
    {
        yield 'dotted dependency' => ['..>', '.[#3388CC].>'];
        yield 'solid dependency' => ['-->', '-[#3388CC]->'];
        yield 'aggregation' => ['o--', 'o-[#3388CC]-'];
        yield 'composition' => ['*--', '*-[#3388CC]-'];
    }

    #[DataProvider('arrowProvider')]
    public function testColorsEveryDependencyArrowWithoutBreakingItsTrunk(string $arrow, string $expectedStyled): void
    {
        $relation = new Relation('A', $arrow, 'B', null, "A {$arrow} B");

        self::assertSame('  A ' . $expectedStyled . ' B', (new Writer())->relation($relation, '#3388CC'));
    }

    public function testThickensInheritanceWithoutColoringIt(): void
    {
        $relation = new Relation('A', '<|--', 'B', null, 'A <|-- B');

        self::assertSame('  A <|-[thickness=2]-- B', (new Writer())->relation($relation, null, 2));
    }

    public function testThickensImplementationWithoutColoringIt(): void
    {
        $relation = new Relation('A', '<|..', 'B', null, 'A <|.. B');

        self::assertSame('  A <|.[thickness=2].. B', (new Writer())->relation($relation, null, 2));
    }

    public function testNoColorAndNoThicknessLeavesTheArrowUntouched(): void
    {
        $relation = new Relation('A', '..>', 'B', null, 'A ..> B');

        self::assertSame('  A ..> B', (new Writer())->relation($relation));
    }

    /**
     * A relation already carrying this tool's own bracketed style (e.g. one
     * of its own generated files fed back in) gets re-styled from scratch,
     * not left with the old embedded value ignored.
     */
    public function testReStylesAnAlreadyBracketedArrowRatherThanIgnoringTheRequest(): void
    {
        $relation = new Relation('A', '-[#111111]->', 'B', null, 'A -[#111111]-> B');

        self::assertSame('  A -[#222222]-> B', (new Writer())->relation($relation, '#222222'));
    }

    public function testAnUnrecognizedArrowIsLeftUntouchedRatherThanCorrupted(): void
    {
        $relation = new Relation('A', '???', 'B', null, 'A ??? B');

        self::assertSame('  A ??? B', (new Writer())->relation($relation, '#3388CC', 2));
    }

    public function testEmitsSourceMultiplicity(): void
    {
        $relation = new Relation('A', '..>', 'B', null, 'A "1" ..> B', sourceMultiplicity: '1');

        self::assertSame('  A "1" ..> B', (new Writer())->relation($relation));
    }

    public function testEmitsTargetMultiplicity(): void
    {
        $relation = new Relation('A', '..>', 'B', null, 'A ..> "*" B', targetMultiplicity: '*');

        self::assertSame('  A ..> "*" B', (new Writer())->relation($relation));
    }

    public function testEmitsBothMultiplicitiesAndLabel(): void
    {
        $relation = new Relation(
            'A',
            '..>',
            'B',
            'owns',
            'A "1" ..> "*" B : owns',
            sourceMultiplicity: '1',
            targetMultiplicity: '*',
        );

        self::assertSame('  A "1" ..> "*" B : owns', (new Writer())->relation($relation));
    }
}
