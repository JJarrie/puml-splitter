<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use InvalidArgumentException;
use RuntimeException;

/**
 * Renders `.puml` files to SVG by invoking `plantuml -charset utf-8 -tsvg`
 * (plan §7). Optional: only used with `--render`. The binary is probed first so a
 * missing PlantUML fails with a clear, actionable message.
 *
 * Files are rendered in batches of {@see $batchSize} per `plantuml` invocation
 * (plan §7 amendment) rather than one process per file — each invocation
 * starts a fresh JVM, so on a project with 100+ output files, one-per-file
 * dominates wall-clock time. The first failing batch aborts the remaining
 * ones (fail-fast, matching the previous per-file behaviour at batch
 * granularity) and throws a {@see SvgRenderException} reporting every file
 * in that batch, PlantUML's combined stdout+stderr output (which
 * conventionally names the offending file itself), and how many files
 * earlier, successful batches already rendered.
 */
final class SvgRenderer
{
    /** @var positive-int */
    private readonly int $batchSize;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $plantumlBin = 'plantuml',
        int $batchSize = 50,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException(sprintf('$batchSize must be >= 1, got %d.', $batchSize));
        }
        $this->batchSize = $batchSize;
    }

    /**
     * @param list<string> $pumlPaths absolute paths of the `.puml` files
     *
     * @return list<string> paths of the generated `.svg` files
     *
     * @throws RuntimeException if the binary is missing
     * @throws SvgRenderException if a batch fails to render
     */
    public function render(array $pumlPaths): array
    {
        $this->ensureAvailable();

        $rendered = 0;
        foreach (array_chunk($pumlPaths, $this->batchSize) as $chunk) {
            $result = $this->runner->run(array_merge(
                [$this->plantumlBin, '-charset', 'utf-8', '-tsvg'],
                $chunk,
            ));
            if ($result->exitCode !== 0) {
                $output = trim(trim($result->stdout) . "\n" . trim($result->stderr));
                throw new SvgRenderException(
                    sprintf(
                        'PlantUML failed to render %d file(s) [%s] (exit %d): %s',
                        count($chunk),
                        implode(', ', $chunk),
                        $result->exitCode,
                        $output !== '' ? $output : '(no output)',
                    ),
                    $rendered,
                    count($pumlPaths),
                );
            }
            $rendered += count($chunk);
        }

        return array_map(
            static fn (string $path): string => (string) preg_replace('/\.puml$/', '.svg', $path),
            $pumlPaths,
        );
    }

    private function ensureAvailable(): void
    {
        $probe = $this->runner->run([$this->plantumlBin, '-version']);
        if ($probe->notFound()) {
            throw new RuntimeException(sprintf(
                'PlantUML binary not found (looked for "%s"). Install PlantUML or pass --plantuml-bin=PATH.',
                $this->plantumlBin,
            ));
        }
        if ($probe->exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'PlantUML probe failed for "%s" (exit %d): %s',
                $this->plantumlBin,
                $probe->exitCode,
                trim($probe->stderr),
            ));
        }
    }
}
