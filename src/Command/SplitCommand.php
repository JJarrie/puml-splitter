<?php

declare(strict_types=1);

namespace PumlSplitter\Command;

use PumlSplitter\Config\SplitConfig;
use PumlSplitter\Graph\AutoClusterer;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\Clusterer;
use PumlSplitter\Graph\ClusterRefiner;
use PumlSplitter\Graph\ConnectedComponents;
use PumlSplitter\Graph\Graph;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\LouvainClusterer;
use PumlSplitter\Graph\MapEmitter;
use PumlSplitter\Graph\MapFileLoader;
use PumlSplitter\Graph\MapPartitioner;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Graph\Partitioner;
use PumlSplitter\Graph\PrefixClusterer;
use PumlSplitter\Output\ClusterPumlGenerator;
use PumlSplitter\Output\IndexHtmlGenerator;
use PumlSplitter\Output\NativeProcessRunner;
use PumlSplitter\Output\OutputGenerator;
use PumlSplitter\Output\OverviewPumlGenerator;
use PumlSplitter\Output\SvgRenderer;
use PumlSplitter\Puml\Model\Document;
use PumlSplitter\Puml\Parser;
use PumlSplitter\Puml\Writer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

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
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Clustering strategy: auto|louvain|prefix|map.', 'auto')
            ->addOption('map', null, InputOption::VALUE_REQUIRED, 'Map file (required with --strategy=map, JSON format).')
            ->addOption('emit-map', null, InputOption::VALUE_REQUIRED, 'Export the computed partition as a map file (any strategy).')
            ->addOption('hub-threshold', null, InputOption::VALUE_REQUIRED, 'In-degree at which a node is a hub.', '8')
            ->addOption('hub-out-threshold', null, InputOption::VALUE_REQUIRED, 'Out-degree at which a node is a hub.', '20')
            ->addOption('hub', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Force an alias to hub status (repeatable).')
            ->addOption('hub-policy', null, InputOption::VALUE_REQUIRED, 'Hub policy: duplicate|separate|exclude.', 'duplicate')
            ->addOption('hub-policy-override', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Per-hub policy override ALIAS:POLICY (repeatable).')
            ->addOption('render', null, InputOption::VALUE_NONE, 'Also render SVGs via plantuml.')
            ->addOption('plantuml-bin', null, InputOption::VALUE_REQUIRED, 'Path to the plantuml binary.', 'plantuml')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional header line injected into every output (repeatable).')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the .puml from standard input.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the split plan without writing files.')
            ->addOption('layout', null, InputOption::VALUE_REQUIRED, 'Layout engine directive: elk|graphviz|none. "none" also disables stereotype colours and navigation hyperlinks.', 'elk')
            ->addOption('edge-color', null, InputOption::VALUE_REQUIRED, 'Dependency edge colouring: target|source|pair|none.', 'target')
            ->addOption('no-legend', null, InputOption::VALUE_NONE, 'Omit the per-cluster legend block.');
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

        $graph = Graph::fromDocument($document);

        foreach ($config->hubs as $alias) {
            if (!$graph->hasNode($alias)) {
                $io->getErrorStyle()->warning(sprintf('Forced hub "%s" is not present in the graph; ignored.', $alias));
            }
        }

        $policies = $this->resolvePolicies($config, $io);
        if ($policies === null) {
            return Command::FAILURE;
        }

        if (!in_array($config->strategy, ['prefix', 'louvain', 'auto', 'map'], true)) {
            $io->getErrorStyle()->error(sprintf('Invalid --strategy value: %s (expected prefix, louvain, auto or map).', $config->strategy));

            return Command::FAILURE;
        }

        if (!in_array($config->layout, ['elk', 'graphviz', 'none'], true)) {
            $io->getErrorStyle()->error(sprintf('Invalid --layout value: %s (expected elk, graphviz or none).', $config->layout));

            return Command::FAILURE;
        }

        if (!in_array($config->edgeColor, ['target', 'source', 'pair', 'none'], true)) {
            $io->getErrorStyle()->error(sprintf('Invalid --edge-color value: %s (expected target, source, pair or none).', $config->edgeColor));

            return Command::FAILURE;
        }

        if ($config->strategy === 'map' && $config->map === null) {
            $io->getErrorStyle()->error('--map=FILE is required with --strategy=map.');

            return Command::FAILURE;
        }

        $strategy = $this->buildStrategy($config, $document, $graph);

        if ($config->strategy === 'map') {
            $partition = $this->partitionWithMap($config, $document, $graph, $policies[0], $policies[1], $strategy, $io);
        } else {
            $partitioner = new Partitioner(
                new HubDetector($config->hubThreshold, $config->hubOutThreshold, $config->hubs, $policies[0], $policies[1]),
                new ConnectedComponents(),
                $strategy,
                new ClusterRefiner($config->minSize, $config->maxSize),
                $config->maxSize,
            );
            $partition = $partitioner->partition($graph, $document);
        }

        if ($partition === null) {
            return Command::FAILURE;
        }

        foreach ($partition->warnings as $warning) {
            $io->getErrorStyle()->warning($warning);
        }

        if ($config->emitMap !== null && !$this->emitMap($partition, $config, $io)) {
            return Command::FAILURE;
        }

        $this->renderPlan($document, $partition, $config, $io, $strategy);

        if (!$config->dryRun) {
            try {
                $this->writeOutput($document, $partition, $config, $io);
            } catch (\RuntimeException $e) {
                $io->getErrorStyle()->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, HubPolicy> $overrides
     */
    private function partitionWithMap(
        SplitConfig $config,
        Document $document,
        Graph $graph,
        HubPolicy $globalPolicy,
        array $overrides,
        Clusterer $fallbackStrategy,
        SymfonyStyle $io,
    ): ?Partition {
        // $config->map is guaranteed non-null here (checked in execute()).
        $loadResult = (new MapFileLoader())->load((string) $config->map);
        if ($loadResult->isFatal()) {
            $io->getErrorStyle()->error((string) $loadResult->fatalError);

            return null;
        }
        $map = $loadResult->map;
        if ($map === null) {
            // Unreachable: isFatal() === false guarantees map !== null.
            $io->getErrorStyle()->error('Failed to load map file.');

            return null;
        }

        // The human map takes priority over degree-based (or even explicit
        // --hub) hub detection (plan §6ter): a mapped alias is never a hub.
        $mappedAliases = array_keys($map->aliasOwners());
        $hubDetector = new HubDetector(
            $config->hubThreshold,
            $config->hubOutThreshold,
            $config->hubs,
            $globalPolicy,
            $overrides,
            $mappedAliases,
        );

        $mapPartitioner = new MapPartitioner(
            $hubDetector,
            new ConnectedComponents(),
            $fallbackStrategy,
            new ClusterRefiner($config->minSize, $config->maxSize),
            $config->minSize,
            $config->maxSize,
        );

        $result = $mapPartitioner->partition($graph, $document, $map);
        if ($result->isFatal()) {
            $io->getErrorStyle()->error((string) $result->fatalError);

            return null;
        }

        return $result->partition;
    }

    private function emitMap(Partition $partition, SplitConfig $config, SymfonyStyle $io): bool
    {
        // $config->emitMap is guaranteed non-null here (checked by the caller).
        $path = (string) $config->emitMap;
        $json = (new MapEmitter())->emit($partition);

        try {
            $filesystem = new Filesystem();
            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.') {
                $filesystem->mkdir($dir);
            }
            $filesystem->dumpFile($path, $json);
        } catch (\Throwable $e) {
            $io->getErrorStyle()->error(sprintf('Failed to write map file %s: %s', $path, $e->getMessage()));

            return false;
        }

        $io->writeln(sprintf('Wrote map to %s.', $path));

        return true;
    }

    private function writeOutput(Document $document, Partition $partition, SplitConfig $config, SymfonyStyle $io): void
    {
        $result = (new OutputGenerator(
            new ClusterPumlGenerator(new Writer()),
            new OverviewPumlGenerator(),
        ))->generate($document, $partition, $config->headers, $config->layout, $config->edgeColor, $config->legend);

        $filesystem = new Filesystem();
        $filesystem->mkdir($config->outputDir);

        $pumlPaths = [];
        foreach ($result->pumlFiles as $file) {
            $path = $config->outputDir . '/' . $file->name;
            $filesystem->dumpFile($path, $file->content);
            $pumlPaths[] = $path;
        }

        $svgAvailable = false;
        if ($config->render) {
            (new SvgRenderer(new NativeProcessRunner(), $config->plantumlBin))->render($pumlPaths);
            $svgAvailable = true;
        }

        $index = (new IndexHtmlGenerator())->generate($result->clusterViews, $result->hubs, $document, $svgAvailable);
        $filesystem->dumpFile($config->outputDir . '/index.html', $index);

        $io->success(sprintf(
            'Wrote %d .puml file(s) + index.html to %s%s.',
            count($result->pumlFiles),
            $config->outputDir,
            $svgAvailable ? ' (with SVGs)' : '',
        ));
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

    /**
     * @return array{0: HubPolicy, 1: array<string, HubPolicy>}|null null on an invalid policy value
     */
    private function resolvePolicies(SplitConfig $config, SymfonyStyle $io): ?array
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

        return [$globalPolicy, $overrides];
    }

    private function buildStrategy(SplitConfig $config, Document $document, Graph $graph): Clusterer
    {
        $shortNames = [];
        foreach ($document->classes() as $alias => $class) {
            $shortNames[$alias] = $class->name;
        }
        $prefix = new PrefixClusterer($shortNames, $config->maxSize);

        return match ($config->strategy) {
            'prefix' => $prefix,
            'louvain' => new LouvainClusterer($graph),
            default => new AutoClusterer($prefix, new LouvainClusterer($graph), $graph, $config->maxSize),
        };
    }

    private function renderPlan(Document $document, Partition $partition, SplitConfig $config, SymfonyStyle $io, Clusterer $strategy): void
    {
        $io->title('puml-splitter — split plan');

        $io->definitionList(
            ['Classes' => (string) $document->classCount()],
            ['Relations' => (string) $document->relationCount()],
            ['Strategy' => $config->strategy],
            ['Hubs' => (string) count($partition->hubs)],
            ['Clusters' => (string) count($partition->clusters)],
        );

        if ($strategy instanceof AutoClusterer) {
            $io->section('Auto strategy (prefix vs louvain)');
            if ($strategy->decisions() === []) {
                $io->writeln('  (no component required splitting)');
            }
            foreach ($strategy->decisions() as $decision) {
                $io->writeln(sprintf(
                    '  %d-node component: prefix cut=%d%s, louvain cut=%d%s → chose %s',
                    $decision->size,
                    $decision->prefixCut,
                    $decision->prefixSatisfies ? '' : ' (oversized)',
                    $decision->louvainCut,
                    $decision->louvainSatisfies ? '' : ' (oversized)',
                    $decision->chosen,
                ));
            }
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
                '%d cluster(s) exceed max-size (%d); the "%s" strategy could not split them cleanly. '
                    . 'Largest: %s (%d).',
                count($oversized),
                $config->maxSize,
                $config->strategy,
                $largest->name,
                $largest->size(),
            ));
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
