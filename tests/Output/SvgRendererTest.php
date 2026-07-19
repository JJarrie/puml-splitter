<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Output\SvgRenderer;
use PumlSplitter\Output\SvgRenderException;
use PumlSplitter\Tests\Support\FakeProcessRunner;
use RuntimeException;

#[CoversClass(SvgRenderer::class)]
final class SvgRendererTest extends TestCase
{
    public function testRendersAllFilesInASingleBatchedCommandWhenWithinBatchSize(): void
    {
        $runner = $this->runner();
        $renderer = new SvgRenderer($runner, 'plantuml');

        $svgs = $renderer->render(['/out/cluster-a.puml', '/out/overview.puml']);

        self::assertSame(['/out/cluster-a.svg', '/out/overview.svg'], $svgs);
        // A version probe followed by one render command covering both files.
        self::assertSame(['plantuml', '-version'], $runner->commands[0]);
        self::assertSame(
            ['plantuml', '-charset', 'utf-8', '-tsvg', '/out/cluster-a.puml', '/out/overview.puml'],
            $runner->commands[1],
        );
        self::assertCount(2, $runner->commands);
    }

    public function testChunksFilesAcrossMultiplePlantumlInvocationsWhenExceedingBatchSize(): void
    {
        $runner = $this->runner();
        $renderer = new SvgRenderer($runner, 'plantuml', batchSize: 2);
        $paths = ['/out/a.puml', '/out/b.puml', '/out/c.puml', '/out/d.puml', '/out/e.puml'];

        $svgs = $renderer->render($paths);

        self::assertSame(
            ['/out/a.svg', '/out/b.svg', '/out/c.svg', '/out/d.svg', '/out/e.svg'],
            $svgs,
        );
        self::assertSame(['plantuml', '-version'], $runner->commands[0]);
        self::assertSame(['plantuml', '-charset', 'utf-8', '-tsvg', '/out/a.puml', '/out/b.puml'], $runner->commands[1]);
        self::assertSame(['plantuml', '-charset', 'utf-8', '-tsvg', '/out/c.puml', '/out/d.puml'], $runner->commands[2]);
        self::assertSame(['plantuml', '-charset', 'utf-8', '-tsvg', '/out/e.puml'], $runner->commands[3]);
        self::assertCount(4, $runner->commands);
    }

    public function testFailingChunkAbortsRemainingChunks(): void
    {
        $runner = $this->runner();
        $runner->renderExit = 1;
        $renderer = new SvgRenderer($runner, 'plantuml', batchSize: 2);

        $this->expectException(RuntimeException::class);

        try {
            $renderer->render(['/out/a.puml', '/out/b.puml', '/out/c.puml', '/out/d.puml']);
        } finally {
            // Version probe + the one failing batch — the second batch is
            // never attempted (fail-fast).
            self::assertCount(2, $runner->commands);
        }
    }

    public function testFailingChunkReportsHowManyFilesRenderedBeforeIt(): void
    {
        $runner = $this->runner();
        $runner->renderExitSequence = [0, 1]; // first batch (a, b) succeeds, second (c, d) fails
        $renderer = new SvgRenderer($runner, 'plantuml', batchSize: 2);

        try {
            $renderer->render(['/out/a.puml', '/out/b.puml', '/out/c.puml', '/out/d.puml', '/out/e.puml']);
            self::fail('Expected SvgRenderException.');
        } catch (SvgRenderException $e) {
            // The first batch (a, b) succeeds before the second (c, d) fails;
            // the third (e) is never attempted.
            self::assertSame(2, $e->renderedCount);
            self::assertSame(5, $e->totalCount);
        }
    }

    public function testFailureMessageCombinesStdoutAndStderr(): void
    {
        $runner = $this->runner();
        $runner->renderExit = 1;
        $runner->renderStdout = 'stdout names the file';
        $runner->renderStderr = 'stderr has a generic message';

        try {
            (new SvgRenderer($runner, 'plantuml'))->render(['/out/cluster-a.puml']);
            self::fail('Expected SvgRenderException.');
        } catch (SvgRenderException $e) {
            self::assertStringContainsString('stdout names the file', $e->getMessage());
            self::assertStringContainsString('stderr has a generic message', $e->getMessage());
        }
    }

    public function testRejectsNonPositiveBatchSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SvgRenderer($this->runner(), 'plantuml', batchSize: 0);
    }

    public function testClearErrorWhenBinaryIsMissing(): void
    {
        $runner = $this->runner();
        $runner->versionExit = 127;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PlantUML binary not found');

        (new SvgRenderer($runner, 'plantuml'))->render(['/out/cluster-a.puml']);
    }

    public function testErrorWhenARenderFails(): void
    {
        $runner = $this->runner();
        $runner->renderExit = 1;
        $runner->renderStderr = 'syntax error';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('syntax error');

        (new SvgRenderer($runner, 'plantuml'))->render(['/out/cluster-a.puml']);
    }

    private function runner(): FakeProcessRunner
    {
        return new FakeProcessRunner();
    }
}
