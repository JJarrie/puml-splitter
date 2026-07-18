<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Output\ClusterView;
use PumlSplitter\Output\IndexHtmlGenerator;
use PumlSplitter\Puml\Model\ClassDeclaration;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;

#[CoversClass(IndexHtmlGenerator::class)]
final class IndexHtmlGeneratorTest extends TestCase
{
    private function document(): Document
    {
        return new Document('@startuml', [], [
            'A1' => new ClassDeclaration('A1', 'Order', ClassKind::Clazz, null),
            'A2' => new ClassDeclaration('A2', 'OrderLine', ClassKind::Clazz, null),
        ], []);
    }

    /**
     * @return list<ClusterView>
     */
    private function views(): array
    {
        return [new ClusterView('alpha', 'Alpha', ['A1', 'A2'])];
    }

    /**
     * @return list<Hub>
     */
    private function hubs(): array
    {
        return [new Hub('H', 12, 0, HubReason::In, false, HubPolicy::Duplicate)];
    }

    public function testListsClustersWithSizeAndComposition(): void
    {
        $html = (new IndexHtmlGenerator())->generate($this->views(), $this->hubs(), $this->document(), false);

        self::assertStringContainsString('<title>puml-splitter', $html);
        self::assertStringContainsString('Alpha <span class="size">(2)</span>', $html);
        // Composition uses display names, not aliases.
        self::assertStringContainsString('<li>Order</li>', $html);
        self::assertStringContainsString('<li>OrderLine</li>', $html);
        self::assertStringContainsString('<td>H</td>', $html);
    }

    public function testEmbedsSvgsOnlyWhenAvailable(): void
    {
        $without = (new IndexHtmlGenerator())->generate($this->views(), $this->hubs(), $this->document(), false);
        $with = (new IndexHtmlGenerator())->generate($this->views(), $this->hubs(), $this->document(), true);

        self::assertStringNotContainsString('<embed', $without);
        self::assertStringContainsString('<embed src="cluster-alpha.svg"', $with);
        self::assertStringContainsString('<embed src="overview.svg"', $with);
    }

    public function testIsDeterministicAndTimestampFree(): void
    {
        $generator = new IndexHtmlGenerator();

        self::assertSame(
            $generator->generate($this->views(), $this->hubs(), $this->document(), true),
            $generator->generate($this->views(), $this->hubs(), $this->document(), true),
        );
    }
}
