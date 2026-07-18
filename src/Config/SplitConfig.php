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
     * @param list<string> $hubs    aliases forced to hub status
     * @param list<string> $headers additional header lines injected into outputs
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
        public array $hubs,
        public string $hubPolicy,
        public bool $render,
        public string $plantumlBin,
        public array $headers,
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
            hubs: self::stringList($input->getOption('hub')),
            hubPolicy: self::str($input->getOption('hub-policy'), 'duplicate'),
            render: (bool) $input->getOption('render'),
            plantumlBin: self::str($input->getOption('plantuml-bin'), 'plantuml'),
            headers: self::stringList($input->getOption('header')),
        );
    }

    private static function str(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
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
}
