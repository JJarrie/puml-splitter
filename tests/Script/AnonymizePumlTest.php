<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Script;

use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Puml\Parser;

/**
 * Integration tests for scripts/anonymize-puml.php (plan §5, §9): the anonymized
 * output must keep the sorted degree sequence, and a deliberately broken variant
 * must fail its own verification with exit code 1 and write nothing.
 */
final class AnonymizePumlTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const SCRIPT = self::ROOT . '/scripts/anonymize-puml.php';

    public function testAnonymizationPreservesSortedDegreeSequence(): void
    {
        $input = self::ROOT . '/tests/fixtures/small.puml';
        $output = (string) tempnam(sys_get_temp_dir(), 'anon');

        $exit = $this->execute(self::SCRIPT, [$input, $output]);

        self::assertSame(0, $exit);
        self::assertSame($this->degreeSequence($input), $this->degreeSequence($output));

        @unlink($output);
    }

    public function testDeliberatelyBrokenTransformFailsVerification(): void
    {
        // A broken copy that maps every token to the same pseudonym collapses
        // distinct names, changing the graph — the guard must reject it.
        $source = (string) file_get_contents(self::SCRIPT);
        $broken = preg_replace("/'Tok' \\. str_pad\\([^;]*\\)/", "'Zz'", $source);
        self::assertIsString($broken);
        self::assertStringContainsString("= 'Zz';", $broken);

        $brokenScript = (string) tempnam(sys_get_temp_dir(), 'anon_broken') . '.php';
        file_put_contents($brokenScript, $broken);
        $output = sys_get_temp_dir() . '/anon_should_not_exist_' . uniqid() . '.puml';

        $exit = $this->execute($brokenScript, [self::ROOT . '/tests/fixtures/small.puml', $output]);

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($output);

        @unlink($brokenScript);
    }

    /**
     * @param list<string> $args
     */
    private function execute(string $script, array $args): int
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>/dev/null';

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return $exit;
    }

    /**
     * @return array{in: list<int>, out: list<int>}
     */
    private function degreeSequence(string $file): array
    {
        $document = (new Parser())->parse((string) file_get_contents($file));
        $graph = Graph::fromDocument($document);

        $in = [];
        $out = [];
        foreach ($graph->nodes() as $node) {
            $in[] = $graph->inDegree($node);
            $out[] = $graph->outDegree($node);
        }
        sort($in);
        sort($out);

        return ['in' => $in, 'out' => $out];
    }
}
