<?php

declare(strict_types=1);

namespace PumlSplitter\Command;

use PumlSplitter\Config\SplitConfig;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
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
 * M2 implements the clustering pipeline (hubs, connected components, prefix
 * strategy, refinement) and the `--dry-run` split plan. File generation is M3.
 */
#[AsCommand(name: 'split', description: 'Split a PlantUML class diagram into readable clustered sub-diagrams.')]
final class SplitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::OPTIONAL, 'Path to the input .puml file (omit when using --stdin).')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory.', './puml-split')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, 'Maximum cluster size.', '25')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Minimum cluster size.', '3')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Clustering strategy: auto|louvain|prefix.', 'auto')
            ->addOption('hub-threshold', null, InputOption::VALUE_REQUIRED, 'In-degree at which a node is a hub.', '8')
            ->addOption('hub-out-threshold', null, InputOption::VALUE_REQUIRED, 'Out-degree at which a node is a hub.', '20')
            ->addOption('hub', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Force an alias to hub status (repeatable).')
            ->addOption('hub-policy', null, InputOption::VALUE_REQUIRED, 'Hub policy: duplicate|separate|exclude.', 'duplicate')
            ->addOption('hub-policy-override', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Per-hub policy override ALIAS:POLICY (repeatable).')
            ->addOption('render', null, InputOption::VALUE_NONE, 'Also render SVGs via plantuml.')
            ->addOption('plantuml-bin', null, InputOption::VALUE_REQUIRED, 'Path to the plantuml binary.', 'plantuml')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional header line injected into every output (repeatable).')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the .puml from standard input.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the split plan without writing files.');
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

        $partitioner = $this->buildPartitioner($config, $document, $io);
        if ($partitioner === null) {
            return Command::FAILURE;
        }

        $graph = Graph::fromDocument($document);

        foreach ($config->hubs as $alias) {
            if (!$graph->hasNode($alias)) {
                $io->getErrorStyle()->warning(sprintf('Forced hub "%s" is not present in the graph; ignored.', $alias));
            }
        }

        $partition = $partitioner->partition($graph, $document);

        $this->renderPlan($document, $partition, $config, $io);

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

    private function buildPartitioner(SplitConfig $config, Document $document, SymfonyStyle $io): ?Partitioner
    {
        $globalPolicy = HubPolicy::tryFrom($config->hubPolicy);
        if ($globalPolicy === null) {
            $io->getErrorStyle()->error(sprintf('Invalid --hub-policy value: %s', $config->hubPolicy));

            return null;
        }

        $overrides = [];
        foreach ($config->hubPolicyOverrides as $alias => $policyName) {
            $policy = HubPolicy::tryFrom($policyName);
            if ($policy === null) {
                $io->getErrorStyle()->error(sprintf('Invalid policy in --hub-policy-override for %s: %s', $alias, $policyName));

                return null;
            }
            $overrides[$alias] = $policy;
        }

        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }

        return new Partitioner(
            hubDetector: new HubDetector($config->hubThreshold, $config->hubOutThreshold, $config->hubs, $globalPolicy, $overrides),
            components: new ConnectedComponents(),
            strategy: new PrefixClusterer($shortNames, $config->maxSize),
            refiner: new ClusterRefiner($config->minSize, $config->maxSize),
            maxSize: $config->maxSize,
        );
    }

    private function renderPlan(Document $document, Partition $partition, SplitConfig $config, SymfonyStyle $io): void
    {
        $io->title('puml-splitter — split plan');

        $io->definitionList(
            ['Classes' => (string) $document->classCount()],
            ['Relations' => (string) $document->relationCount()],
            ['Hubs' => (string) count($partition->hubs)],
            ['Clusters' => (string) count($partition->clusters)],
        );

        if ($config->strategy !== 'prefix') {
            $io->note(sprintf('Strategy "%s" is not fully available yet; using the prefix strategy until Louvain lands (M4).', $config->strategy));
        }

        $io->section('Hubs');
        if ($partition->hubs === []) {
            $io->writeln('  (none detected)');
        } else {
            $io->table(
                ['Alias', 'In-degree', 'Out-degree', 'Reason', 'Policy'],
                array_map(
                    static fn (Hub $hub): array => [
                        $hub->alias,
                        (string) $hub->inDegree,
                        (string) $hub->outDegree,
                        $hub->reason->value,
                        $hub->policy->value,
                    ],
                    $partition->hubs,
                ),
            );
        }

        $io->section('Clusters');
        $rows = [];
        foreach ($partition->clusters as $index => $cluster) {
            $rows[] = [
                $cluster->name,
                (string) $cluster->size(),
                (string) $partition->internalByCluster[$index],
                (string) $partition->externalByCluster[$index],
            ];
        }
        $io->table(['Cluster', 'Size', 'Internal edges', 'External edges'], $rows);

        $io->definitionList(
            ['Internal edges' => (string) $partition->internalEdges],
            ['Inter-cluster edges' => (string) $partition->interClusterEdges],
            ['Hub edges' => (string) $partition->hubEdges],
            ['Total (must equal relations)' => sprintf('%d / %d', $partition->totalEdges(), $document->relationCount())],
        );

        $oversized = $partition->oversizedClusters($config->maxSize);
        if ($oversized !== []) {
            $largest = $this->largest($oversized);
            $io->warning(sprintf(
                '%d cluster(s) exceed max-size (%d); the prefix strategy could not split them cleanly. '
                    . 'Largest: %s (%d). This is expected input for Louvain (M4).',
                count($oversized),
                $config->maxSize,
                $largest->name,
                $largest->size(),
            ));
        }

        if (!$config->dryRun) {
            $io->note('File generation (.puml / index.html / SVG) is not implemented yet (M3). Showing the plan only.');
        }
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function largest(array $clusters): Cluster
    {
        $largest = $clusters[0];
        foreach ($clusters as $cluster) {
            if ($cluster->size() > $largest->size()) {
                $largest = $cluster;
            }
        }

        return $largest;
    }
}
