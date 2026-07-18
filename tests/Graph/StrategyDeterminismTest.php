<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Clusterer;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;

/**
 * Full-pipeline determinism: two complete runs must produce identical partitions,
 * order included (plan §11 / M4 requirement).
 */
#[CoversClass(LouvainClusterer::class)]
#[CoversClass(AutoClusterer::class)]
final class StrategyDeterminismTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/very-large.puml';

    /**
     * @return iterable<string, array{string}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'louvain' => ['louvain'];
        yield 'auto' => ['auto'];
    }

    #[DataProvider('strategyProvider')]
    public function testTwoRunsAreIdentical(string $strategy): void
    {
        self::assertSame(
            $this->serialize($this->partitionFor($strategy)),
            $this->serialize($this->partitionFor($strategy)),
        );
    }

    private function partitionFor(string $strategy): Partition
    {
        $content = file_get_contents(self::FIXTURE);
        self::assertIsString($content);
        $document = (new Parser())->parse($content);
        $graph = Graph::fromDocument($document);

        $partitioner = new Partitioner(
            new HubDetector(8, 20, [], HubPolicy::Duplicate, []),
            new ConnectedComponents(),
            $this->strategy($strategy, $document, $graph),
            new ClusterRefiner(3, 25),
            25,
        );

        return $partitioner->partition($graph, $document);
    }

    private function strategy(string $name, Document $document, Graph $graph): Clusterer
    {
        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }
        $prefix = new PrefixClusterer($shortNames, 25);
        $louvain = new LouvainClusterer($graph);

        return match ($name) {
            'louvain' => $louvain,
            default => new AutoClusterer($prefix, $louvain, $graph, 25),
        };
    }

    /**
     * @return list<string>
     */
    private function serialize(Partition $partition): array
    {
        return array_map(static fn (Cluster $c): string => $c->name . ':' . implode(',', $c->members), $partition->clusters);
    }
}
