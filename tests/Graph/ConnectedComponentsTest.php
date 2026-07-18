<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(ConnectedComponents::class)]
final class ConnectedComponentsTest extends TestCase
{
    public function testExcludingAHubSplitsTheGraph(): void
    {
        // H bridges {A,B,C} and {D,E}; F is isolated.
        $graph = GraphFactory::fromEdges(
            [['A', 'B'], ['B', 'C'], ['D', 'E'], ['A', 'H'], ['D', 'H']],
            ['F'],
        );

        $components = (new ConnectedComponents())->compute($graph, ['H']);

        self::assertSame(
            [['A', 'B', 'C'], ['D', 'E'], ['F']],
            $components,
        );
    }

    public function testWithoutExclusionTheHubKeepsComponentsConnected(): void
    {
        $graph = GraphFactory::fromEdges(
            [['A', 'B'], ['B', 'C'], ['D', 'E'], ['A', 'H'], ['D', 'H']],
            ['F'],
        );

        $components = (new ConnectedComponents())->compute($graph);

        self::assertSame(
            [['A', 'B', 'C', 'D', 'E', 'H'], ['F']],
            $components,
        );
    }

    public function testComponentsAndMembersAreSortedDeterministically(): void
    {
        $graph = GraphFactory::fromEdges([['z', 'y'], ['m', 'n']]);

        $components = (new ConnectedComponents())->compute($graph);

        // Members sorted alphabetically, components ordered by smallest alias.
        self::assertSame([['m', 'n'], ['y', 'z']], $components);
    }
}
