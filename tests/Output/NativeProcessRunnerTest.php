<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Output\NativeProcessRunner;

#[CoversClass(NativeProcessRunner::class)]
final class NativeProcessRunnerTest extends TestCase
{
    public function testCapturesStdoutStderrAndExitCode(): void
    {
        $result = (new NativeProcessRunner())->run([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);',
        ]);

        self::assertSame(3, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
    }

    public function testDoesNotDeadlockOnLargeStdoutAndStderr(): void
    {
        // Both streams exceed a pipe buffer (~64 KiB); the old pipe-draining
        // implementation would deadlock here.
        $size = 200_000;
        $result = (new NativeProcessRunner())->run([
            PHP_BINARY,
            '-r',
            sprintf('fwrite(STDOUT, str_repeat("a", %d)); fwrite(STDERR, str_repeat("b", %d));', $size, $size),
        ]);

        self::assertSame(0, $result->exitCode);
        self::assertSame($size, strlen($result->stdout));
        self::assertSame($size, strlen($result->stderr));
    }

    public function testMissingBinaryReturnsNonZeroExit(): void
    {
        $result = (new NativeProcessRunner())->run(['/nonexistent/puml-splitter-binary-' . uniqid()]);

        self::assertNotSame(0, $result->exitCode);
    }
}
