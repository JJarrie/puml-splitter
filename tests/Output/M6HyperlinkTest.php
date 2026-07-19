<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Output\ClusterPumlGenerator;
use PumlSplitter\Output\OutputGenerator;
use PumlSplitter\Output\OutputResult;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Writer;

/**
 * Navigation hyperlinks (plan §7bis): relative `[[cluster-<slug>.svg]]` on
 * external nodes and overview packages, and the `separate`/`exclude` hub
 * policy edge cases.
 *
 * Same scenario as {@see \PumlSplitter\Tests\Output\ClusterPumlGeneratorTest}
 * (A1/A2 in "alpha", B1 in "beta", Hd/Hs/He duplicate/separate/exclude hubs)
 * so the hyperlink behaviour is checked against a fixture already known to
 * exercise every boundary/hub path.
 */
#[CoversNothing]
final class M6HyperlinkTest extends TestCase
{
    private function generate(string $layout = 'elk'): OutputResult
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

        return (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($document, $partition, [], $layout, 'none', false);
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

    public function testRegularExternalNodeLinksToItsHomeClusterSvg(): void
    {
        $alpha = $this->files($this->generate())['cluster-alpha.puml'];

        self::assertStringContainsString('class "B1" as B1 <<external: beta>> [[cluster-beta.svg]]', $alpha);
    }

    public function testSeparateHubLinksToTheSharedTypesCluster(): void
    {
        $alpha = $this->files($this->generate())['cluster-alpha.puml'];

        self::assertStringContainsString('class "Hs" as Hs <<external: shared_types>> [[cluster-shared_types.svg]]', $alpha);
    }

    public function testExcludedHubHasNoNodeAndSoNoLink(): void
    {
        $alpha = $this->files($this->generate())['cluster-alpha.puml'];

        self::assertStringNotContainsString('He', $alpha);
        self::assertStringNotContainsString('[[cluster-he', strtolower($alpha));
    }

    public function testDuplicateHubIsInlinedNotLinked(): void
    {
        $alpha = $this->files($this->generate())['cluster-alpha.puml'];

        // Hd is a full duplicate, not a boundary stub, so it never gets a link.
        self::assertStringContainsString('class "Hd" as Hd <<shared>>', $alpha);
        self::assertStringNotContainsString('Hd [[', $alpha);
        self::assertStringNotContainsString('Hd <<shared>> [[', $alpha);
    }

    public function testOverviewPackageLinksToItsOwnCluster(): void
    {
        $overview = $this->files($this->generate())['overview.puml'];

        self::assertStringContainsString('package "alpha (2)" as alpha [[cluster-alpha.svg]] {', $overview);
        self::assertStringContainsString('package "beta (1)" as beta [[cluster-beta.svg]] {', $overview);
    }

    public function testLayoutNoneDisablesHyperlinksEntirely(): void
    {
        foreach ($this->files($this->generate('none')) as $name => $content) {
            self::assertStringNotContainsString('[[', $content, $name);
        }
    }

    /**
     * Every hyperlink target actually corresponds to a `.puml` file produced
     * by this same run (swapping its `.svg` extension for `.puml`) — a link
     * is never emitted to a cluster that doesn't exist in the current output set.
     */
    public function testEveryHyperlinkTargetsAFileProducedByThisRun(): void
    {
        $result = $this->generate();
        $producedPuml = [];
        foreach ($result->pumlFiles as $file) {
            $producedPuml[$file->name] = true;
        }

        $targets = [];
        foreach ($result->pumlFiles as $file) {
            preg_match_all('/\[\[([^\]]+)\.svg\]\]/', $file->content, $matches);
            foreach ($matches[1] as $slug) {
                $targets[] = $slug . '.puml';
            }
        }

        self::assertNotEmpty($targets);
        foreach ($targets as $target) {
            self::assertArrayHasKey($target, $producedPuml, "link target $target was not produced by this run");
        }
    }

    public function testAllLinkedFilesRoundTripWithoutWarning(): void
    {
        foreach ($this->files($this->generate()) as $name => $content) {
            $parser = new \PumlSplitter\Puml\Parser();
            $parser->parse($content);
            self::assertSame([], $parser->warnings(), "warnings in $name");
        }
    }
}
