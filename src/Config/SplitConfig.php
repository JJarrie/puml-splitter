<?php

declare(strict_types=1);

namespace PumlSplitter\Config;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolved, immutable options for a `split` run.
 *
 * M1 only acts on {@see $stdin}, {@see $input} and {@see $dryRun}; the remaining
 * fields are parsed and carried so later milestones (clustering, output) can use
 * them without reshaping the command.
 */
final readonly class SplitConfig
{
    /**
     * @param list<string>          $hubs               aliases forced to hub status
     * @param array<string, string> $hubPolicyOverrides per-alias policy overrides (alias => policy string)
     * @param list<string>          $headers            additional header lines injected into outputs
     */
    public function __construct(
        public ?string $input,
        public bool $stdin,
        public bool $dryRun,
        public string $outputDir,
        public int $maxSize,
        public int $minSize,
        public string $strategy,
        public int $hubThreshold,
        public int $hubOutThreshold,
        public array $hubs,
        public string $hubPolicy,
        public array $hubPolicyOverrides,
        public bool $render,
        public string $plantumlBin,
        public array $headers,
        public string $layout,
        public string $edgeColor,
        public bool $legend,
        public ?string $map,
        public ?string $emitMap,
    ) {
    }

    public static function fromInput(InputInterface $input): self
    {
        $inputArg = $input->getArgument('input');

        return new self(
            input: is_string($inputArg) ? $inputArg : null,
            stdin: (bool) $input->getOption('stdin'),
            dryRun: (bool) $input->getOption('dry-run'),
            outputDir: self::str($input->getOption('output'), './puml-split'),
            maxSize: self::int($input->getOption('max-size'), 25),
            minSize: self::int($input->getOption('min-size'), 3),
            strategy: self::str($input->getOption('strategy'), 'auto'),
            hubThreshold: self::int($input->getOption('hub-threshold'), 8),
            hubOutThreshold: self::int($input->getOption('hub-out-threshold'), 20),
            hubs: self::stringList($input->getOption('hub')),
            hubPolicy: self::str($input->getOption('hub-policy'), 'duplicate'),
            hubPolicyOverrides: self::keyValueList($input->getOption('hub-policy-override')),
            render: (bool) $input->getOption('render'),
            plantumlBin: self::str($input->getOption('plantuml-bin'), 'plantuml'),
            headers: self::stringList($input->getOption('header')),
            layout: self::str($input->getOption('layout'), 'elk'),
            edgeColor: self::str($input->getOption('edge-color'), 'target'),
            legend: !(bool) $input->getOption('no-legend'),
            map: self::nullableStr($input->getOption('map')),
            emitMap: self::nullableStr($input->getOption('emit-map')),
        );
    }

    private static function str(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function nullableStr(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Parses repeatable `ALIAS:VALUE` options into a map (last occurrence wins).
     *
     * @return array<string, string>
     */
    private static function keyValueList(mixed $value): array
    {
        $out = [];
        foreach (self::stringList($value) as $item) {
            $pos = strpos($item, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $out[substr($item, 0, $pos)] = substr($item, $pos + 1);
        }

        return $out;
    }
}

