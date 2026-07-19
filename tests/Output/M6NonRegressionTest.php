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
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;

/**
 * M6 non-regression (plan §7bis): `--layout=none --edge-color=none --no-legend`
 * must reproduce the exact pre-M6 (M5) output, byte for byte. The expected
 * strings below were captured by running this exact fixture through the
 * unmodified M5 `OutputGenerator` *before* any M6 code was written — see the
 * milestone's commit message for how they were produced. If this test ever
 * needs updating, it must be re-derived from M5 code, not from a later M6
 * revision (that would defeat the point of a non-regression check).
 */
#[CoversNothing]
final class M6NonRegressionTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/m6-regression.puml';
    private const MAX_SIZE = 3;
    private const MIN_SIZE = 2;

    /**
     * @return array<string, string>
     */
    private function generate(): array
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

        $result = (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($document, $partition, [], 'none', 'none', false);

        $files = [];
        foreach ($result->pumlFiles as $file) {
            $files[$file->name] = $file->content;
        }

        return $files;
    }

    public function testAllStyleFlagsDisabledReproducesM5Output(): void
    {
        $files = $this->generate();

        self::assertSame(<<<'PUML'
            @startuml cluster-invoice
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
              class "Logger" as Logger <<shared>> #LightYellow {
                +log(msg)
              }
              class "OrderHeader" as OrderHeader <<external: order>> #DDDDDD
              InvoiceHeader ..> InvoiceLine
              InvoiceHeader ..> InvoiceTax
              InvoiceHeader ..> Logger
              InvoiceHeader ..> OrderHeader : refs
              InvoiceLine <|-- AbstractLine
              InvoiceLine ..> InvoiceTax
              InvoiceLine ..> Logger
              InvoiceTax ..> Logger
            @enduml

            PUML, $files['cluster-invoice.puml']);

        self::assertSame(<<<'PUML'
            @startuml cluster-order
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
              class "Logger" as Logger <<shared>> #LightYellow {
                +log(msg)
              }
              class "InvoiceHeader" as InvoiceHeader <<external: invoice>> #DDDDDD
              InvoiceHeader ..> OrderHeader : refs
              OrderHeader ..> Logger
              OrderHeader ..> OrderLine
              OrderHeader ..> OrderTax
              OrderLine ..> Logger
              OrderLine ..> OrderTax
              OrderLine <|.. Payable
              OrderTax ..> Logger
            @enduml

            PUML, $files['cluster-order.puml']);

        self::assertSame(<<<'PUML'
            @startuml overview
              package "Invoice (4)" as invoice {
              }
              package "Order (4)" as order {
              }
              invoice -[thickness=1]-> order : 1
            @enduml

            PUML, $files['overview.puml']);
    }
}
