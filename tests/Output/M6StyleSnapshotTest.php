<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Output\ClusterPumlGenerator;
use PumlSplitter\Output\OutputGenerator;
use PumlSplitter\Output\OutputResult;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;

/**
 * §9 snapshot coverage for the (layout, edge-color) combinations the plan
 * calls out by name, on the same fixture as {@see M6NonRegressionTest}.
 */
#[CoversNothing]
final class M6StyleSnapshotTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/m6-regression.puml';
    private const MAX_SIZE = 3;
    private const MIN_SIZE = 2;

    private function generate(string $layout, string $edgeColor, bool $legend): OutputResult
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $document = (new Parser())->parse($content);

        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        $partitioner = new Partitioner(
            new HubDetector(5, 20, [], HubPolicy::Duplicate, []),
            new ConnectedComponents(),
            new PrefixClusterer($shortNames, self::MAX_SIZE),
            new ClusterRefiner(self::MIN_SIZE, self::MAX_SIZE),
            self::MAX_SIZE,
        );
        $partition = $partitioner->partition(Graph::fromDocument($document), $document);

        return (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($document, $partition, [], $layout, $edgeColor, $legend);
    }

    /**
     * @return array<string, string>
     */
    private function files(OutputResult $result): array
    {
        $map = [];
        foreach ($result->pumlFiles as $file) {
            $map[$file->name] = $file->content;
        }

        return $map;
    }

    public function testElkLayoutWithTargetEdgeColor(): void
    {
        $files = $this->files($this->generate('elk', 'target', true));

        self::assertSame(<<<'PUML'
            @startuml cluster-invoice
            !pragma layout elk
            hide <<shared>> members
              abstract class "AbstractLine" as AbstractLine
              class "InvoiceHeader" as InvoiceHeader {
                -id : int
              }
              class "InvoiceLine" as InvoiceLine {
                -amount : float
              }
              class "InvoiceTax" as InvoiceTax {
                -rate : float
              }
              class "Logger" as Logger <<shared>> #FFF3E0;line:E65100 {
                +log(msg)
              }
              class "OrderHeader" as OrderHeader <<external: order>> [[cluster-order.svg]] #F5F5F5;line:9E9E9E;line.dashed;text:9E9E9E
              InvoiceHeader .[#7124A8].> InvoiceLine
              InvoiceHeader .[#A89824].> InvoiceTax
              InvoiceHeader .[#2492A8].> Logger
              InvoiceHeader .[#A8246B].> OrderHeader : refs
              InvoiceLine <|-[thickness=2]-- AbstractLine
              InvoiceLine .[#A89824].> InvoiceTax
              InvoiceLine .[#2492A8].> Logger
              InvoiceTax .[#2492A8].> Logger
            legend bottom
              4 classes, 8 edges
              Edge color: by target class
              <<shared>>: hub duplicated here
              <<external>>: defined in another cluster
            endlegend
            @enduml

            PUML, $files['cluster-invoice.puml']);

        self::assertSame(<<<'PUML'
            @startuml overview
            !pragma layout elk
              package "Invoice (4)" as invoice [[cluster-invoice.svg]] {
              }
              package "Order (4)" as order [[cluster-order.svg]] {
              }
              invoice -[thickness=1]-> order : 1
            @enduml

            PUML, $files['overview.puml']);
    }

    public function testGraphvizLayoutWithSourceEdgeColor(): void
    {
        $files = $this->files($this->generate('graphviz', 'source', true));

        self::assertSame(<<<'PUML'
            @startuml cluster-order
            skinparam linetype polyline
            skinparam nodesep 20
            skinparam ranksep 30
            hide <<shared>> members
              class "OrderHeader" as OrderHeader {
                -id : int
              }
              class "OrderLine" as OrderLine {
                -qty : int
              }
              class "OrderTax" as OrderTax {
                -rate : float
              }
              interface "Payable" as Payable
              class "Logger" as Logger <<shared>> #FFF3E0;line:E65100 {
                +log(msg)
              }
              class "InvoiceHeader" as InvoiceHeader <<external: invoice>> [[cluster-invoice.svg]] #F5F5F5;line:9E9E9E;line.dashed;text:9E9E9E
              InvoiceHeader .[#A82424].> OrderHeader : refs
              OrderHeader .[#7124A8].> Logger
              OrderHeader .[#7124A8].> OrderLine
              OrderHeader .[#7124A8].> OrderTax
              OrderLine .[#A89824].> Logger
              OrderLine .[#A89824].> OrderTax
              OrderLine <|.[thickness=2].. Payable
              OrderTax .[#2492A8].> Logger
            legend bottom
              4 classes, 8 edges
              Edge color: by source class
              <<shared>>: hub duplicated here
              <<external>>: defined in another cluster
            endlegend
            @enduml

            PUML, $files['cluster-order.puml']);
    }

    public function testNoneLayoutNoneEdgeColorNoLegendHasNoM6Additions(): void
    {
        $files = $this->files($this->generate('none', 'none', false));

        foreach ($files as $name => $content) {
            self::assertStringNotContainsString('!pragma', $content, $name);
            self::assertStringNotContainsString('skinparam linetype', $content, $name);
            self::assertStringNotContainsString('legend', $content, $name);
            self::assertStringNotContainsString('[[', $content, $name);
            self::assertStringNotContainsString('FFF3E0', $content, $name);
        }
    }

    public function testEveryGeneratedPumlRoundTripsWithoutWarningAcrossCombinations(): void
    {
        $combinations = [
            ['elk', 'target', true],
            ['graphviz', 'source', true],
            ['none', 'pair', true],
            ['elk', 'none', false],
        ];

        foreach ($combinations as [$layout, $edgeColor, $legend]) {
            $files = $this->files($this->generate($layout, $edgeColor, $legend));
            foreach ($files as $name => $content) {
                $parser = new Parser();
                $parser->parse($content);
                self::assertSame([], $parser->warnings(), "warnings in $layout/$edgeColor/$name");
            }
        }
    }
}
