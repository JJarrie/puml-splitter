<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\Hub;
use PumlSplitter\Puml\Model\Document;

/**
 * Emits the aggregated overview map (plan §7): one package per cluster and one
 * `A --> B : n` edge per ordered cluster pair, `n` being the number of aggregated
 * inter-cluster edges, with a proportional thickness (1..4).
 */
final class OverviewPumlGenerator
{
    /**
     * @param list<ClusterView>     $clusters
     * @param array<string, string> $clusterSlugOf non-hub alias => cluster slug
     * @param array<string, Hub>    $hubOf
     * @param list<string>          $additionalHeaders
     */
    public function generate(
        array $clusters,
        array $clusterSlugOf,
        Document $document,
        array $hubOf,
        array $additionalHeaders = [],
        string $layout = 'none',
    ): string {
        $lines = ['@startuml overview'];
        foreach ($document->headerLines as $header) {
            $lines[] = $header;
        }
        // Layout directives before user headers, so the latter stay
        // authoritative on any conflict (plan §7bis).
        foreach (LayoutDirectives::forLayout($layout) as $directive) {
            $lines[] = $directive;
        }
        foreach ($additionalHeaders as $header) {
            $lines[] = $header;
        }

        // `--layout=none` is also the switch for navigation hyperlinks (plan
        // §7bis); see GenerationContext::isStyled().
        $styled = GenerationContext::layoutIsStyled($layout);

        $views = $clusters;
        usort($views, static fn (ClusterView $a, ClusterView $b): int => strcmp($a->slug, $b->slug));
        foreach ($views as $view) {
            // A cluster name may be a raw alias, so keep the quoted title valid.
            $title = str_replace('"', "'", $view->name);
            $link = $styled ? ' [[cluster-' . $view->slug . '.svg]]' : '';
            $lines[] = sprintf('  package "%s (%d)" as %s%s {', $title, $view->size(), $view->slug, $link);
            $lines[] = '  }';
        }

        $counts = $this->aggregate($document, $clusterSlugOf, $hubOf);
        $max = $counts === [] ? 1 : max($counts);

        foreach ($this->sortedKeys($counts) as $key) {
            [$from, $to] = explode("\x00", $key);
            $count = $counts[$key];
            $lines[] = sprintf('  %s -[thickness=%d]-> %s : %d', $from, $this->thickness($count, $max), $to, $count);
        }

        $lines[] = '@enduml';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $clusterSlugOf
     * @param array<string, Hub>    $hubOf
     *
     * @return array<string, int> "fromSlug\0toSlug" => aggregated edge count
     */
    private function aggregate(Document $document, array $clusterSlugOf, array $hubOf): array
    {
        $counts = [];
        foreach ($document->relations() as $relation) {
            if (isset($hubOf[$relation->source]) || isset($hubOf[$relation->target])) {
                continue;
            }

            $from = $clusterSlugOf[$relation->source] ?? null;
            $to = $clusterSlugOf[$relation->target] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }

            $key = $from . "\x00" . $to;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function thickness(int $count, int $max): int
    {
        if ($max <= 1) {
            return 1;
        }

        return 1 + (int) round(($count - 1) / ($max - 1) * 3);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<string>
     */
    private function sortedKeys(array $counts): array
    {
        $keys = array_keys($counts);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
