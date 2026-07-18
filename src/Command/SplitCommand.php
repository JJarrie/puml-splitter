<?php

declare(strict_types=1);

namespace PumlSplitter\Command;

use PumlSplitter\Config\SplitConfig;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Splits a PlantUML class diagram into clustered sub-diagrams.
 *
 * M1 implements input handling, parsing and the `--dry-run` statistics view.
 * Clustering and output generation arrive in later milestones.
 */
#[AsCommand(name: 'split', description: 'Split a PlantUML class diagram into readable clustered sub-diagrams.')]
final class SplitCommand extends Command
{
    private const TOP_N = 10;

    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::OPTIONAL, 'Path to the input .puml file (omit when using --stdin).')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory.', './puml-split')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, 'Maximum cluster size.', '25')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Minimum cluster size.', '3')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Clustering strategy: auto|louvain|prefix.', 'auto')
            ->addOption('hub-threshold', null, InputOption::VALUE_REQUIRED, 'In-degree at which a node is a hub.', '8')
            ->addOption('hub', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Force an alias to hub status (repeatable).')
            ->addOption('hub-policy', null, InputOption::VALUE_REQUIRED, 'Hub policy: duplicate|separate|exclude.', 'duplicate')
            ->addOption('render', null, InputOption::VALUE_NONE, 'Also render SVGs via plantuml.')
            ->addOption('plantuml-bin', null, InputOption::VALUE_REQUIRED, 'Path to the plantuml binary.', 'plantuml')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional header line injected into every output (repeatable).')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the .puml from standard input.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the split plan / statistics without writing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = SplitConfig::fromInput($input);

        $content = $this->readInput($config, $io);
        if ($content === null) {
            return Command::FAILURE;
        }

        $parser = new Parser();
        $document = $parser->parse($content);

        foreach ($parser->warnings() as $warning) {
            $io->getErrorStyle()->warning($warning);
        }

        if ($document->classCount() === 0) {
            $io->getErrorStyle()->error('No class declarations found in the input; nothing to split.');

            return Command::FAILURE;
        }

        $this->renderStats($document, Graph::fromDocument($document), $config, $io);

        return Command::SUCCESS;
    }

    private function readInput(SplitConfig $config, SymfonyStyle $io): ?string
    {
        if ($config->stdin) {
            $content = @stream_get_contents(STDIN);
            if ($content === false) {
                $io->getErrorStyle()->error('Failed to read from standard input.');

                return null;
            }

            return $content;
        }

        if ($config->input === null) {
            $io->getErrorStyle()->error('Provide an input file path or use --stdin.');

            return null;
        }

        if (!is_file($config->input) || !is_readable($config->input)) {
            $io->getErrorStyle()->error(sprintf('Cannot read input file: %s', $config->input));

            return null;
        }

        $content = @file_get_contents($config->input);
        if ($content === false) {
            $io->getErrorStyle()->error(sprintf('Cannot read input file: %s', $config->input));

            return null;
        }

        return $content;
    }

    private function renderStats(Document $document, Graph $graph, SplitConfig $config, SymfonyStyle $io): void
    {
        $io->title('puml-splitter — diagram statistics');

        $io->definitionList(
            ['Classes' => (string) $document->classCount()],
            ['Relations' => (string) $document->relationCount()],
            ['Graph nodes' => (string) $graph->nodeCount()],
            ['Passthrough lines' => (string) count($document->passthrough)],
        );

        $kinds = $this->countKinds($document);
        $io->section('Declaration kinds');
        $io->table(['Kind', 'Count'], array_map(
            static fn (string $kind, int $count): array => [$kind, (string) $count],
            array_keys($kinds),
            array_values($kinds),
        ));

        $io->section(sprintf('Top %d by in-degree (hub threshold: %d)', self::TOP_N, $config->hubThreshold));
        $io->table(['Alias', 'In-degree', 'Hub?'], array_map(
            fn (array $row): array => [
                $row['alias'],
                (string) $row['degree'],
                $row['degree'] >= $config->hubThreshold ? 'yes' : '',
            ],
            $graph->topByInDegree(self::TOP_N),
        ));

        $io->section(sprintf('Top %d by out-degree', self::TOP_N));
        $io->table(['Alias', 'Out-degree'], array_map(
            static fn (array $row): array => [$row['alias'], (string) $row['degree']],
            $graph->topByOutDegree(self::TOP_N),
        ));

        if (!$config->dryRun) {
            $io->note('Clustering and file generation are not implemented yet (M2/M3). Showing statistics only.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function countKinds(Document $document): array
    {
        $counts = [];
        foreach (ClassKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        foreach ($document->classes() as $class) {
            $counts[$class->kind->value]++;
        }

        return $counts;
    }
}
