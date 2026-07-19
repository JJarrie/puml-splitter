<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\AutoDecision;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\LeidenClusterer;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(AutoClusterer::class)]
#[CoversClass(AutoDecision::class)]
final class AutoClustererTest extends TestCase
{
    public function testChoosesLeidenWhenItCutsFewerEdges(): void
    {
        // Two structural triangles bridged, but the names cross-cut the structure,
        // so the prefix split cuts every triangle edge (6) while leiden cuts 1.
        $graph = GraphFactory::fromEdges(array_merge(
            $this->clique(['x1', 'x2', 'x3']),
            $this->clique(['y1', 'y2', 'y3']),
            [['x1', 'y1']],
        ));
        $names = ['x1' => 'FooA', 'x2' => 'BarA', 'x3' => 'BazA', 'y1' => 'FooB', 'y2' => 'BarB', 'y3' => 'BazB'];

        $auto = $this->auto($graph, $names, 10);
        $clusters = $auto->cluster(['x1', 'x2', 'x3', 'y1', 'y2', 'y3']);

        self::assertSame([['x1', 'x2', 'x3'], ['y1', 'y2', 'y3']], $this->communities($clusters));

        $decision = $auto->decisions()[0];
        self::assertSame('leiden', $decision->chosen);
        self::assertSame(1, $decision->leidenCut);
        self::assertSame(6, $decision->prefixCut);
    }

    public function testChoosesPrefixWhenLeidenViolatesSize(): void
    {
        // A 6-clique: leiden keeps it as one community (size 6 > max), while the
        // prefix names split it into two size-3 groups that satisfy the bound.
        $graph = GraphFactory::fromEdges($this->clique(['z1', 'z2', 'z3', 'z4', 'z5', 'z6']));
        $names = ['z1' => 'AlphaP', 'z2' => 'AlphaQ', 'z3' => 'AlphaR', 'z4' => 'BetaP', 'z5' => 'BetaQ', 'z6' => 'BetaR'];

        $auto = $this->auto($graph, $names, 3);
        $clusters = $auto->cluster(['z1', 'z2', 'z3', 'z4', 'z5', 'z6']);

        self::assertSame([['z1', 'z2', 'z3'], ['z4', 'z5', 'z6']], $this->communities($clusters));

        $decision = $auto->decisions()[0];
        self::assertSame('prefix', $decision->chosen);
        self::assertTrue($decision->prefixSatisfies);
        self::assertFalse($decision->leidenSatisfies);
    }

    public function testPrefersPrefixOnATie(): void
    {
        // Names aligned with structure: both strategies yield the same partition
        // and the same cut count, so the tie resolves to prefix.
        $graph = GraphFactory::fromEdges(array_merge(
            $this->clique(['x1', 'x2', 'x3']),
            $this->clique(['y1', 'y2', 'y3']),
            [['x1', 'y1']],
        ));
        $names = ['x1' => 'XeeA', 'x2' => 'XeeB', 'x3' => 'XeeC', 'y1' => 'YeeA', 'y2' => 'YeeB', 'y3' => 'YeeC'];

        $auto = $this->auto($graph, $names, 10);
        $auto->cluster(['x1', 'x2', 'x3', 'y1', 'y2', 'y3']);

        $decision = $auto->decisions()[0];
        self::assertSame('prefix', $decision->chosen);
        self::assertSame($decision->prefixCut, $decision->leidenCut);
    }

    /**
     * @param array<string, string> $names
     */
    private function auto(Graph $graph, array $names, int $maxSize): AutoClusterer
    {
        return new AutoClusterer(
            new PrefixClusterer($names, $maxSize),
            new LeidenClusterer($graph),
            $graph,
            $maxSize,
        );
    }

    /**
     * @param list<string> $nodes
     *
     * @return list<array{0: string, 1: string}>
     */
    private function clique(array $nodes): array
    {
        $edges = [];
        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $edges[] = [$nodes[$i], $nodes[$j]];
            }
        }

        return $edges;
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<list<string>>
     */
    private function communities(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $cluster) {
            $out[] = $cluster->members;
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a[0] ?? '', $b[0] ?? ''));

        return $out;
    }
}
