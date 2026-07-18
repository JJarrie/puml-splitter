<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Output\ClusterPumlGenerator;
use PumlSplitter\Output\GeneratedFile;
use PumlSplitter\Output\OutputGenerator;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;

#[CoversClass(ClusterPumlGenerator::class)]
#[CoversClass(OutputGenerator::class)]
#[CoversClass(Writer::class)]
final class ClusterPumlGeneratorTest extends TestCase
{
    /**
     * Scenario exercising all rendering paths at once:
     *  - A1/A2 in cluster "alpha", B1 in cluster "beta" (inter-cluster edge),
     *  - Hd a duplicate hub, Hs a separate hub, He an excluded hub.
     *
     * @return array<string, GeneratedFile>
     */
    private function generate(): array
    {
        $classes = [
            'A1' => new ClassDeclaration('A1', 'A1', ClassKind::Clazz, ['    -x : int', '    -y : Beta']),
            'A2' => new ClassDeclaration('A2', 'A2', ClassKind::Clazz, []),
            'B1' => new ClassDeclaration('B1', 'B1', ClassKind::Clazz, ['    -z : int']),
            'Hd' => new ClassDeclaration('Hd', 'Hd', ClassKind::Clazz, ['    +h : int']),
            'Hs' => new ClassDeclaration('Hs', 'Hs', ClassKind::Clazz, ['    +s : int']),
            'He' => new ClassDeclaration('He', 'He', ClassKind::Clazz, ['    +e : int']),
        ];
        $relations = [
            new Relation('A1', '..>', 'A2', null, 'A1 ..> A2'),
            new Relation('A1', '..>', 'B1', null, 'A1 ..> B1'),
            new Relation('A1', '..>', 'Hd', null, 'A1 ..> Hd'),
            new Relation('A1', '..>', 'Hs', null, 'A1 ..> Hs'),
            new Relation('A1', '..>', 'He', null, 'A1 ..> He'),
        ];
        $document = new Document('@startuml', [], $classes, $relations);

        $partition = new Partition(
            clusters: [new Cluster('alpha', ['A1', 'A2']), new Cluster('beta', ['B1'])],
            hubs: [
                new Hub('Hd', 5, 0, HubReason::In, false, HubPolicy::Duplicate),
                new Hub('He', 9, 0, HubReason::In, false, HubPolicy::Exclude),
                new Hub('Hs', 0, 25, HubReason::Out, false, HubPolicy::Separate),
            ],
            internalEdges: 0,
            interClusterEdges: 0,
            hubEdges: 0,
            internalByCluster: [0, 0],
            externalByCluster: [0, 0],
        );

        $result = (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($document, $partition);

        $byName = [];
        foreach ($result->pumlFiles as $file) {
            $byName[$file->name] = $file;
        }

        return $byName;
    }

    public function testClusterSnapshotCoversMembersHubsAndBoundaries(): void
    {
        $expected = <<<'PUML'
            @startuml cluster-alpha
            hide <<shared>> members
              class "A1" as A1 {
                -x : int
                -y : Beta
              }
              class "A2" as A2 {
              }
              class "Hd" as Hd <<shared>> #LightYellow {
                +h : int
              }
              class "B1" as B1 <<external: beta>> #DDDDDD
              class "Hs" as Hs <<external: shared_types>> #DDDDDD
              A1 ..> A2
              A1 ..> B1
              A1 ..> Hd
              A1 ..> Hs
            @enduml

            PUML;

        self::assertSame($expected, $this->generate()['cluster-alpha.puml']->content);
    }

    public function testExcludedHubAndItsEdgeAreAbsent(): void
    {
        $alpha = $this->generate()['cluster-alpha.puml']->content;

        self::assertStringNotContainsString('He', $alpha);
        self::assertStringNotContainsString('A1 ..> He', $alpha);
    }

    public function testSeparateHubGetsItsOwnSharedTypesFile(): void
    {
        $files = $this->generate();

        self::assertArrayHasKey('cluster-shared_types.puml', $files);
        self::assertStringContainsString('class "Hs" as Hs {', $files['cluster-shared_types.puml']->content);
    }

    public function testEveryGeneratedPumlRoundTripsWithoutWarning(): void
    {
        foreach ($this->generate() as $file) {
            if (!str_ends_with($file->name, '.puml')) {
                continue;
            }
            $parser = new Parser();
            $parser->parse($file->content);
            self::assertSame([], $parser->warnings(), "warnings in {$file->name}");
        }
    }

    public function testBodiesAreByteIdentical(): void
    {
        $document = (new Parser())->parse($this->generate()['cluster-alpha.puml']->content);

        self::assertSame(['    -x : int', '    -y : Beta'], $document->getClass('A1')?->bodyLines);
    }
}
