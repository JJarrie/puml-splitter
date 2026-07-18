<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\Hub;
use PumlSplitter\Puml\Model\Document;

/**
 * Emits a standalone `index.html` (inline CSS, no external assets, no timestamps
 * so runs are byte-identical): the overview plus every cluster with its size and
 * class composition, the detected hubs, and `<embed>`s of the SVGs when rendered.
 */
final class IndexHtmlGenerator
{
    /**
     * @param list<ClusterView> $clusters
     * @param list<Hub>         $hubs
     */
    public function generate(array $clusters, array $hubs, Document $document, bool $svgAvailable): string
    {
        $views = $clusters;
        usort($views, static fn (ClusterView $a, ClusterView $b): int => strcmp($a->slug, $b->slug));

        $hubList = $hubs;
        usort($hubList, static fn (Hub $a, Hub $b): int => strcmp($a->alias, $b->alias));

        $html = [];
        $html[] = '<!DOCTYPE html>';
        $html[] = '<html lang="en">';
        $html[] = '<head>';
        $html[] = '<meta charset="utf-8">';
        $html[] = '<title>puml-splitter — diagram map</title>';
        $html[] = '<style>' . $this->css() . '</style>';
        $html[] = '</head>';
        $html[] = '<body>';
        $html[] = '<h1>Diagram map</h1>';

        $html[] = '<section><h2>Overview</h2>';
        if ($svgAvailable) {
            $html[] = '<embed src="overview.svg" type="image/svg+xml">';
        }
        $html[] = sprintf('<p>%d clusters, %d hubs.</p>', count($views), count($hubList));
        $html[] = '</section>';

        foreach ($views as $view) {
            $html[] = '<section class="cluster">';
            $html[] = sprintf('<h2>%s <span class="size">(%d)</span></h2>', $this->escape($view->name), $view->size());
            if ($svgAvailable) {
                $html[] = sprintf('<embed src="cluster-%s.svg" type="image/svg+xml">', $this->escape($view->slug));
            }
            $html[] = '<ul>';
            foreach ($view->members as $alias) {
                $html[] = '<li>' . $this->escape($this->displayName($alias, $document)) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '</section>';
        }

        if ($hubList !== []) {
            $html[] = '<section><h2>Hubs</h2><table>';
            $html[] = '<tr><th>Alias</th><th>In</th><th>Out</th><th>Reason</th><th>Policy</th></tr>';
            foreach ($hubList as $hub) {
                $html[] = sprintf(
                    '<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                    $this->escape($hub->alias),
                    $hub->inDegree,
                    $hub->outDegree,
                    $hub->reason->value,
                    $hub->policy->value,
                );
            }
            $html[] = '</table></section>';
        }

        $html[] = '</body>';
        $html[] = '</html>';

        return implode("\n", $html) . "\n";
    }

    private function displayName(string $alias, Document $document): string
    {
        $class = $document->getClass($alias);

        return $class !== null ? $class->name : $alias;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return 'body{font-family:system-ui,sans-serif;margin:2rem;color:#222}'
            . 'h1{font-size:1.5rem}h2{font-size:1.1rem;border-bottom:1px solid #ddd}'
            . '.size{color:#888;font-weight:normal}'
            . 'section.cluster{margin:1rem 0}'
            . 'ul{columns:3;list-style:square}'
            . 'table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:.2rem .5rem;text-align:left}'
            . 'embed{display:block;max-width:100%;height:auto;margin:.5rem 0}';
    }
}
