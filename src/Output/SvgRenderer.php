<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use RuntimeException;

/**
 * Renders `.puml` files to SVG by invoking `plantuml -charset utf-8 -tsvg`
 * (plan §7). Optional: only used with `--render`. The binary is probed first so a
 * missing PlantUML fails with a clear, actionable message.
 */
final class SvgRenderer
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $plantumlBin = 'plantuml',
    ) {
    }

    /**
     * @param list<string> $pumlPaths absolute paths of the `.puml` files
     *
     * @return list<string> paths of the generated `.svg` files
     *
     * @throws RuntimeException if the binary is missing or a render fails
     */
    public function render(array $pumlPaths): array
    {
        $this->ensureAvailable();

        $svgPaths = [];
        foreach ($pumlPaths as $pumlPath) {
            $result = $this->runner->run([$this->plantumlBin, '-charset', 'utf-8', '-tsvg', $pumlPath]);
            if ($result->exitCode !== 0) {
                throw new RuntimeException(sprintf(
                    'PlantUML failed to render %s (exit %d): %s',
                    $pumlPath,
                    $result->exitCode,
                    trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout),
                ));
            }
            $svgPaths[] = (string) preg_replace('/\.puml$/', '.svg', $pumlPath);
        }

        return $svgPaths;
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
