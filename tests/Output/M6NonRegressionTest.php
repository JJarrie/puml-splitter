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
 * strings below were originally captured by running this exact fixture
 * through the unmodified M5 `OutputGenerator` *before* any M6 code was
 * written. If this test ever needs updating for an M6/style reason, it must
 * be re-derived from M5 code, not from a later M6 revision (that would
 * defeat the point of a non-regression check).
 *
 * Re-baselined once, for a non-style reason: the original snapshot had
 * `cluster-invoice`/`cluster-order` at 4 members each against `MAX_SIZE = 3`
 * — `ClusterRefiner::mostConnected()` didn't check max-size before merging
 * a small cluster into its most-connected neighbour, so `Payable` and
 * `AbstractLine` were merged in anyway. That's the exact class of bug this
 * project's `ClusterRefiner` refine-loop work fixed (see docs/plan.md §6.4
 * amendment); the frozen values below are the same fixture re-run through
 * the corrected (still style-flags-off) pipeline, both clusters now
 * correctly within [min-size, max-size] and the two size-excluded members
 * routed to `misc`, exactly as `--min-size`/`--max-size` document.
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
              abstract class "AbstractLine" as AbstractLine <<external: misc>> #DDDDDD
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
              class "Logger" as Logger <<shared>> #LightYellow {
                +log(msg)
              }
              class "InvoiceHeader" as InvoiceHeader <<external: invoice>> #DDDDDD
              interface "Payable" as Payable <<external: misc>> #DDDDDD
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
            @startuml cluster-misc
              abstract class "AbstractLine" as AbstractLine
              interface "Payable" as Payable
              class "InvoiceLine" as InvoiceLine <<external: invoice>> #DDDDDD
              class "OrderLine" as OrderLine <<external: order>> #DDDDDD
              InvoiceLine <|-- AbstractLine
              OrderLine <|.. Payable
            @enduml

            PUML, $files['cluster-misc.puml']);

        self::assertSame(<<<'PUML'
            @startuml overview
              package "Invoice (3)" as invoice {
              }
              package "misc (2)" as misc {
              }
              package "Order (3)" as order {
              }
              invoice -[thickness=1]-> misc : 1
              invoice -[thickness=1]-> order : 1
              order -[thickness=1]-> misc : 1
            @enduml

            PUML, $files['overview.puml']);
    }
}
