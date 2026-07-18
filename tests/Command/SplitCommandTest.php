<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Command\SplitCommand;
use PumlSplitter\Config\SplitConfig;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SplitCommand::class)]
#[CoversClass(SplitConfig::class)]
final class SplitCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testPrintsStatsForRealFixture(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/very-large.puml', '--dry-run' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Classes', $display);
        self::assertStringContainsString('156', $display);
        // The package base is the highest out-degree node, so it always renders.
        self::assertStringContainsString('Type155', $display);
    }

    public function testMissingFileIsFatal(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/does-not-exist.puml'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Cannot read input file', $tester->getErrorOutput());
    }

    public function testNoInputIsFatal(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--stdin', $tester->getErrorOutput());
    }

    public function testZeroClassesIsFatal(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/no-classes.puml'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No class declarations', $tester->getErrorOutput());
    }

    public function testUnknownLineWarnsButSucceeds(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/unknown-line.puml'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Unrecognized line', $tester->getErrorOutput());
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->add(new SplitCommand());
        $command = $application->find('split');

        return new CommandTester($command);
    }
}
