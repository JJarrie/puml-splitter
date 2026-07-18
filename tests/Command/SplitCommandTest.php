<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Command\SplitCommand;
use PumlSplitter\Config\SplitConfig;
use PumlSplitter\Puml\Parser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SplitCommand::class)]
#[CoversClass(SplitConfig::class)]
final class SplitCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testPrintsSplitPlanForRealFixture(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/very-large.puml', '--dry-run' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();

        // Summary + hub table with the out-only hub and its differentiated policy.
        self::assertStringContainsString('Classes', $display);
        self::assertStringContainsString('156', $display);
        self::assertStringContainsString('separate', $display);
        // Token anonymization keeps prefixes, so the plan reports real clusters.
        self::assertStringContainsString('Inter-cluster edges', $display);
    }

    public function testForcedHubNotInGraphWarns(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            [
                'input' => self::FIXTURES . '/very-large.puml',
                '--dry-run' => true,
                '--hub' => ['DoesNotExist'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Forced hub "DoesNotExist" is not present', $tester->getErrorOutput());
    }

    public function testInvalidStrategyIsFatal(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            [
                'input' => self::FIXTURES . '/very-large.puml',
                '--dry-run' => true,
                '--strategy' => 'louvian',
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --strategy', $tester->getErrorOutput());
    }

    public function testInvalidHubPolicyOverrideIsFatal(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(
            [
                'input' => self::FIXTURES . '/very-large.puml',
                '--dry-run' => true,
                '--hub-policy-override' => ['SomeAlias:nonsense'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid policy', $tester->getErrorOutput());
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

    public function testWritesOutputFilesWithoutRender(): void
    {
        $dir = sys_get_temp_dir() . '/puml-out-' . uniqid();
        $tester = $this->tester();

        $exit = $tester->execute(
            ['input' => self::FIXTURES . '/very-large.puml', '--output' => $dir],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($dir . '/index.html');
        self::assertFileExists($dir . '/overview.puml');

        $clusters = glob($dir . '/cluster-*.puml') ?: [];
        self::assertNotEmpty($clusters);
        foreach ($clusters as $file) {
            $parser = new Parser();
            $parser->parse((string) file_get_contents($file));
            self::assertSame([], $parser->warnings(), "warnings in {$file}");
        }

        foreach ((array) glob($dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($dir);
    }

    public function testDryRunWritesNoFiles(): void
    {
        $dir = sys_get_temp_dir() . '/puml-dry-' . uniqid();
        $tester = $this->tester();

        $tester->execute(
            ['input' => self::FIXTURES . '/very-large.puml', '--output' => $dir, '--dry-run' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertDirectoryDoesNotExist($dir);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->add(new SplitCommand());
        $command = $application->find('split');

        return new CommandTester($command);
    }
}
