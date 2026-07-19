<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\Partition;
use PumlSplitter\Puml\Model\Document;

/**
 * Turns a {@see Partition} into the set of `.puml` files (one per cluster, an
 * optional `shared-types` file for `separate` hubs, and the overview). Pure and
 * deterministic — no I/O; the command writes the returned files.
 */
final class OutputGenerator
{
    public function __construct(
        private readonly ClusterPumlGenerator $clusterGenerator,
        private readonly OverviewPumlGenerator $overviewGenerator,
    ) {
    }

    /**
     * @param list<string> $additionalHeaders
     */
    public function generate(
        Document $document,
        Partition $partition,
        array $additionalHeaders = [],
        string $layout = 'none',
        string $edgeColor = 'none',
        bool $legend = false,
    ): OutputResult {
        /** @var array<string, true> $used */
        $used = [];
        /** @var array<string, string> $clusterSlugOf */
        $clusterSlugOf = [];
        $slugs = [];
        foreach ($partition->clusters as $index => $cluster) {
            $slug = $this->uniqueSlug($this->slugify($cluster->name), $used);
            $slugs[$index] = $slug;
            foreach ($cluster->members as $member) {
                $clusterSlugOf[$member] = $slug;
            }
        }

        /** @var array<string, Hub> $hubOf */
        $hubOf = [];
        $separateHubs = [];
        foreach ($partition->hubs as $hub) {
            $hubOf[$hub->alias] = $hub;
            if ($hub->policy === HubPolicy::Separate) {
                $separateHubs[] = $hub->alias;
            }
        }
        sort($separateHubs, SORT_STRING);

        $sharedTypesSlug = $separateHubs === [] ? 'shared_types' : $this->uniqueSlug('shared_types', $used);
        $context = new GenerationContext($document, $clusterSlugOf, $hubOf, $sharedTypesSlug, $additionalHeaders, $layout, $edgeColor, $legend);

        $pumlFiles = [];
        $clusterViews = [];
        $overviewViews = [];

        foreach ($partition->clusters as $index => $cluster) {
            $slug = $slugs[$index];
            $content = $this->clusterGenerator->generate('cluster-' . $slug, $cluster->members, $context);
            $pumlFiles[] = new GeneratedFile('cluster-' . $slug . '.puml', $content);
            $view = new ClusterView($slug, $cluster->name, $cluster->members);
            $clusterViews[] = $view;
            $overviewViews[] = $view;
        }

        if ($separateHubs !== []) {
            $content = $this->clusterGenerator->generate('cluster-' . $sharedTypesSlug, $separateHubs, $context);
            $pumlFiles[] = new GeneratedFile('cluster-' . $sharedTypesSlug . '.puml', $content);
            $clusterViews[] = new ClusterView($sharedTypesSlug, 'shared-types', $separateHubs);
        }

        $overview = $this->overviewGenerator->generate($overviewViews, $clusterSlugOf, $document, $hubOf, $additionalHeaders, $layout);
        $pumlFiles[] = new GeneratedFile('overview.puml', $overview);

        return new OutputResult($pumlFiles, $clusterViews, $partition->hubs);
    }

    private function slugify(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');

        return $slug === '' ? 'cluster' : $slug;
    }

    /**
     * @param array<string, true> $used
     */
    private function uniqueSlug(string $base, array &$used): string
    {
        $candidate = $base;
        $suffix = 1;
        while (isset($used[$candidate])) {
            $suffix++;
            $candidate = $base . '_' . $suffix;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
