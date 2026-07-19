<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Output\EdgeColorer;

#[CoversClass(EdgeColorer::class)]
final class EdgeColorerTest extends TestCase
{
    public function testKnownIndexesProduceExpectedHexColors(): void
    {
        // Golden-angle (137.508°) hue steps at fixed 65% saturation / 40%
        // lightness; values pinned so a future change to the formula shows
        // up as a diff here rather than silently, per plan §9.
        self::assertSame('#A82424', EdgeColorer::colorForIndex(0));
        self::assertSame('#24A84A', EdgeColorer::colorForIndex(1));
        self::assertSame('#7124A8', EdgeColorer::colorForIndex(2));
        self::assertSame('#A89824', EdgeColorer::colorForIndex(3));
    }

    public function testIsDeterministicAcrossCalls(): void
    {
        self::assertSame(EdgeColorer::colorForIndex(7), EdgeColorer::colorForIndex(7));
        self::assertSame(EdgeColorer::palette(['a', 'b', 'c']), EdgeColorer::palette(['a', 'b', 'c']));
    }

    public function testPaletteAssignsByPositionInTheGivenList(): void
    {
        $palette = EdgeColorer::palette(['Alpha', 'Bravo', 'Charlie']);

        self::assertSame(EdgeColorer::colorForIndex(0), $palette['Alpha']);
        self::assertSame(EdgeColorer::colorForIndex(1), $palette['Bravo']);
        self::assertSame(EdgeColorer::colorForIndex(2), $palette['Charlie']);
    }

    /**
     * A key's colour depends only on its position within the list the caller
     * supplies — never on some other, unrelated set of keys. In this tool,
     * that list is always scoped to a single diagram (plan §7bis: "la couleur
     * d'un alias ne dépend que de sa position alphabétique dans SON
     * diagramme"), so a class gaining/losing neighbours in a *different*
     * cluster's file must never change colours in this one. EdgeColorer
     * itself is agnostic to what the keys mean — this test documents the
     * property the caller relies on: same key, same relative position,
     * same colour, regardless of what other keys are (or aren't) present
     * around it elsewhere.
     */
    public function testColorDependsOnlyOnPositionNotOnOtherKeysPresent(): void
    {
        $smallDiagram = EdgeColorer::palette(['Alpha', 'Bravo']);
        $largeDiagram = EdgeColorer::palette(['Alpha', 'Bravo', 'Zulu', 'Yankee', 'Xray']);

        // "Alpha" and "Bravo" sit at the same positions (0, 1) in both lists,
        // so they keep their colour even though "largeDiagram" has classes
        // "smallDiagram" has never heard of.
        self::assertSame($smallDiagram['Alpha'], $largeDiagram['Alpha']);
        self::assertSame($smallDiagram['Bravo'], $largeDiagram['Bravo']);
    }

    public function testEmptyListYieldsEmptyPalette(): void
    {
        self::assertSame([], EdgeColorer::palette([]));
    }
}
