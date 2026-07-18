<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Output\SvgRenderer;
use PumlSplitter\Tests\Support\FakeProcessRunner;
use RuntimeException;

#[CoversClass(SvgRenderer::class)]
final class SvgRendererTest extends TestCase
{
    public function testRendersEachFileWhenBinaryIsPresent(): void
    {
        $runner = $this->runner();
        $renderer = new SvgRenderer($runner, 'plantuml');

        $svgs = $renderer->render(['/out/cluster-a.puml', '/out/overview.puml']);

        self::assertSame(['/out/cluster-a.svg', '/out/overview.svg'], $svgs);
        // A version probe followed by one render command per file.
        self::assertSame(['plantuml', '-version'], $runner->commands[0]);
        self::assertSame(['plantuml', '-charset', 'utf-8', '-tsvg', '/out/cluster-a.puml'], $runner->commands[1]);
        self::assertSame(['plantuml', '-charset', 'utf-8', '-tsvg', '/out/overview.puml'], $runner->commands[2]);
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
