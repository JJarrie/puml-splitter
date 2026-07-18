<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Output\ClusterPumlGenerator;
use PumlSplitter\Output\OutputGenerator;
use PumlSplitter\Output\OutputResult;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;

/**
 * The full §9 integration on the ~150-class fixture: node conservation, edge
 * conservation, size bounds, round-trip, and total determinism.
 */
#[CoversClass(OutputGenerator::class)]
#[CoversClass(ClusterPumlGenerator::class)]
final class OutputGeneratorIntegrationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/very-large.puml';
    private const MAX_SIZE = 25;
    private const MIN_SIZE = 3;

    private Document $document;
    private Partition $partition;

    protected function setUp(): void
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $this->document = (new Parser())->parse($content);

        $shortNames = [];
        foreach ($this->document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        $partitioner = new Partitioner(
            new HubDetector(8, 20, [], HubPolicy::Duplicate, []),
            new ConnectedComponents(),
            new PrefixClusterer($shortNames, self::MAX_SIZE),
            new ClusterRefiner(self::MIN_SIZE, self::MAX_SIZE),
            self::MAX_SIZE,
        );
        $this->partition = $partitioner->partition(Graph::fromDocument($this->document), $this->document);
    }

    private function generate(): OutputResult
    {
        return (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($this->document, $this->partition);
    }

    public function testEveryGeneratedPumlReparsesWithoutWarning(): void
    {
        foreach ($this->generate()->pumlFiles as $file) {
            $parser = new Parser();
            $parser->parse($file->content);
            self::assertSame([], $parser->warnings(), "warnings in {$file->name}");
        }
    }

    public function testEdgeConservation(): void
    {
        self::assertSame(
            $this->document->relationCount(),
            $this->partition->internalEdges + $this->partition->interClusterEdges + $this->partition->hubEdges,
        );
    }

    public function testNodeConservationEachNonHubExactlyOnce(): void
    {
        $hubAliases = [];
        foreach ($this->partition->hubs as $hub) {
            $hubAliases[$hub->alias] = true;
        }

        $home = [];
        foreach ($this->generate()->pumlFiles as $file) {
            if (!str_starts_with($file->name, 'cluster-')) {
                continue;
            }
            foreach ((new Parser())->parse($file->content)->classes() as $alias => $class) {
                if ($class->isExternal()) {
                    continue; // boundary stubs are not the node's home
                }
                if ($class->stereotype !== null && str_contains($class->stereotype, 'shared')) {
                    continue; // duplicate hubs are shared across files
                }
                $home[$alias] = ($home[$alias] ?? 0) + 1;
            }
        }

        foreach (Graph::fromDocument($this->document)->nodes() as $node) {
            if (isset($hubAliases[$node])) {
                continue;
            }
            self::assertSame(1, $home[$node] ?? 0, "node {$node} should appear as home exactly once");
        }
    }

    public function testClusterSizesAreWithinBounds(): void
    {
        foreach ($this->partition->clusters as $cluster) {
            self::assertLessThanOrEqual(self::MAX_SIZE, $cluster->size());
            if ($cluster->name !== ClusterRefiner::MISC) {
                self::assertGreaterThanOrEqual(self::MIN_SIZE, $cluster->size());
            }
        }
    }

    public function testGenerationIsDeterministic(): void
    {
        $first = $this->serialize($this->generate());
        $second = $this->serialize($this->generate());

        self::assertSame($first, $second);
    }

    /**
     * @return array<string, string>
     */
    private function serialize(OutputResult $result): array
    {
        $map = [];
        foreach ($result->pumlFiles as $file) {
            $map[$file->name] = $file->content;
        }
        ksort($map);

        return $map;
    }
}
