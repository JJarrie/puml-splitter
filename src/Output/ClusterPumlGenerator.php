<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Writer;

/**
 * Emits the `.puml` for one cluster file (plan §7 / §7bis): original + extra
 * headers, full member declarations (byte-identical bodies), referenced hubs
 * rendered per their resolved policy, and boundary `<<external: …>>` stub
 * nodes. An edge is emitted only if it touches a member of this file, which
 * also guarantees no edge between two external nodes is ever written.
 *
 * `--layout=none` (plan §7bis) is the single switch for the whole M6
 * presentation layer here: the layout pragma, the `<<shared>>`/`<<external>>`
 * recolouring (reverting to the plain M5 inline colours), and navigation
 * hyperlinks. Edge colouring/thickness and the legend are independently
 * controlled by their own flags and stay active even under `--layout=none`.
 */
final class ClusterPumlGenerator
{
    private const SHARED_COLOR_LEGACY = '#LightYellow';
    private const EXTERNAL_COLOR_LEGACY = '#DDDDDD';
    private const SHARED_COLOR_STYLED = '#FFF3E0;line:E65100';
    private const EXTERNAL_COLOR_STYLED = '#F5F5F5;line:9E9E9E;line.dashed;text:9E9E9E';

    private const INHERITANCE_ARROWS = ['<|--', '<|..'];

    public function __construct(private readonly Writer $writer)
    {
    }

    /**
     * @param list<string> $members full member aliases of this file
     */
    public function generate(string $slug, array $members, GenerationContext $ctx): string
    {
        $memberSet = array_fill_keys($members, true);

        /** @var list<Relation> $edges */
        $edges = [];
        /** @var array<string, true> $sharedHubs duplicate hubs referenced here */
        $sharedHubs = [];
        /** @var array<string, string> $boundary external alias => stereotype label */
        $boundary = [];

        foreach ($ctx->document->relations() as $relation) {
            $source = $relation->source;
            $target = $relation->target;

            if ($this->isExcluded($source, $ctx) || $this->isExcluded($target, $ctx)) {
                continue;
            }

            $sourceMember = isset($memberSet[$source]);
            $targetMember = isset($memberSet[$target]);
            if (!$sourceMember && !$targetMember) {
                continue;
            }

            $edges[] = $relation;

            if (!$sourceMember) {
                $this->registerBoundary($source, $ctx, $sharedHubs, $boundary);
            }
            if (!$targetMember) {
                $this->registerBoundary($target, $ctx, $sharedHubs, $boundary);
            }
        }

        return $this->render($slug, $members, $sharedHubs, $boundary, $edges, $ctx);
    }

    /**
     * @param array<string, true>   $sharedHubs
     * @param array<string, string> $boundary
     */
    private function registerBoundary(string $alias, GenerationContext $ctx, array &$sharedHubs, array &$boundary): void
    {
        $hub = $ctx->hubOf[$alias] ?? null;
        if ($hub !== null) {
            if ($hub->policy === HubPolicy::Duplicate) {
                $sharedHubs[$alias] = true;
            } elseif ($hub->policy === HubPolicy::Separate) {
                $boundary[$alias] = 'external: ' . $ctx->sharedTypesSlug;
            }

            return;
        }

        $boundary[$alias] = 'external: ' . ($ctx->clusterSlugOf[$alias] ?? 'external');
    }

    private function isExcluded(string $alias, GenerationContext $ctx): bool
    {
        $hub = $ctx->hubOf[$alias] ?? null;

        return $hub !== null && $hub->policy === HubPolicy::Exclude;
    }

    /**
     * @param list<string>          $members
     * @param array<string, true>   $sharedHubs
     * @param array<string, string> $boundary
     * @param list<Relation>        $edges
     */
    private function render(string $slug, array $members, array $sharedHubs, array $boundary, array $edges, GenerationContext $ctx): string
    {
        $lines = ['@startuml ' . $slug];

        foreach ($ctx->document->headerLines as $header) {
            $lines[] = $header;
        }
        // Layout directives before user headers, so the latter stay
        // authoritative on any conflict (plan §7bis).
        foreach (LayoutDirectives::forLayout($ctx->layout) as $directive) {
            $lines[] = $directive;
        }
        foreach ($ctx->additionalHeaders as $header) {
            $lines[] = $header;
        }
        if ($sharedHubs !== []) {
            $lines[] = 'hide <<shared>> members';
        }

        $memberList = $members;
        sort($memberList, SORT_STRING);
        foreach ($memberList as $alias) {
            foreach ($this->declareMember($alias, $ctx) as $line) {
                $lines[] = $line;
            }
        }

        $sharedList = array_keys($sharedHubs);
        sort($sharedList, SORT_STRING);
        foreach ($sharedList as $alias) {
            foreach ($this->declareShared($alias, $ctx) as $line) {
                $lines[] = $line;
            }
        }

        $boundaryList = array_keys($boundary);
        sort($boundaryList, SORT_STRING);
        foreach ($boundaryList as $alias) {
            $lines[] = $this->declareExternal($alias, $boundary[$alias], $ctx);
        }

        $sortedEdges = $this->sortEdges($edges);
        $palette = $this->palette($members, $sharedList, $boundaryList, $sortedEdges, $ctx);
        foreach ($sortedEdges as $relation) {
            $lines[] = $this->writeEdge($relation, $palette, $ctx);
        }

        if ($ctx->legend) {
            foreach ($this->legend($members, $sortedEdges, $sharedHubs, $boundary, $ctx) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '@enduml';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Diagram-local colour lookup (plan §7bis / §9): built once per file from
     * only this file's own node aliases and edge pairs — see
     * {@see EdgeColorer} for why that scoping is deliberate. `target`/`source`
     * key on the node alias; `pair` keys on "source\0target" among this
     * file's own dependency edges.
     *
     * @param list<string>   $members
     * @param list<string>   $sharedList
     * @param list<string>   $boundaryList
     * @param list<Relation> $sortedEdges
     *
     * @return array<string, string>
     */
    private function palette(array $members, array $sharedList, array $boundaryList, array $sortedEdges, GenerationContext $ctx): array
    {
        if ($ctx->edgeColor === 'none') {
            return [];
        }

        if ($ctx->edgeColor === 'pair') {
            $pairKeys = [];
            foreach ($sortedEdges as $relation) {
                if ($this->isInheritance($relation)) {
                    continue;
                }
                $pairKeys[] = $relation->source . "\x00" . $relation->target;
            }
            $pairKeys = array_values(array_unique($pairKeys));
            sort($pairKeys, SORT_STRING);

            return EdgeColorer::palette($pairKeys);
        }

        $nodeKeys = array_values(array_unique([...$members, ...$sharedList, ...$boundaryList]));
        sort($nodeKeys, SORT_STRING);

        return EdgeColorer::palette($nodeKeys);
    }

    /**
     * @param array<string, string> $palette
     */
    private function writeEdge(Relation $relation, array $palette, GenerationContext $ctx): string
    {
        if ($ctx->edgeColor === 'none') {
            return $this->writer->relation($relation);
        }

        if ($this->isInheritance($relation)) {
            return $this->writer->relation($relation, null, 2);
        }

        $key = match ($ctx->edgeColor) {
            'source' => $relation->source,
            'pair' => $relation->source . "\x00" . $relation->target,
            default => $relation->target,
        };

        return $this->writer->relation($relation, $palette[$key] ?? null);
    }

    private function isInheritance(Relation $relation): bool
    {
        return in_array($relation->arrow, self::INHERITANCE_ARROWS, true);
    }

    /**
     * @return list<string>
     */
    private function declareMember(string $alias, GenerationContext $ctx): array
    {
        $class = $ctx->document->getClass($alias);
        if ($class === null) {
            return [$this->writer->stub(ClassKind::Clazz, $alias, $alias)];
        }

        return $this->writer->declaration($class);
    }

    /**
     * @return list<string>
     */
    private function declareShared(string $alias, GenerationContext $ctx): array
    {
        $color = $ctx->isStyled() ? self::SHARED_COLOR_STYLED : self::SHARED_COLOR_LEGACY;

        $class = $ctx->document->getClass($alias);
        if ($class === null) {
            return [$this->writer->stub(ClassKind::Clazz, $alias, $alias, '<<shared>>', $color)];
        }

        return $this->writer->declaration($class, '<<shared>>', $color);
    }

    private function declareExternal(string $alias, string $label, GenerationContext $ctx): string
    {
        $class = $ctx->document->getClass($alias);
        $name = $class !== null ? $class->name : $alias;
        $kind = $class !== null ? $class->kind : ClassKind::Clazz;
        $color = $ctx->isStyled() ? self::EXTERNAL_COLOR_STYLED : self::EXTERNAL_COLOR_LEGACY;

        // $label is "external: <slug>" (plan §7): the target cluster's own
        // slug either way, including the shared-types cluster under the
        // `separate` hub policy — no special-casing needed here.
        $link = null;
        if ($ctx->isStyled()) {
            $targetSlug = substr($label, strlen('external: '));
            $link = 'cluster-' . $targetSlug . '.svg';
        }

        return $this->writer->stub($kind, $name, $alias, '<<' . $label . '>>', $color, $link);
    }

    /**
     * Active conventions only (plan §7bis) — no line for a disabled feature,
     * nor for a stereotype absent from this particular file.
     *
     * @param list<string>           $members
     * @param list<Relation>         $sortedEdges
     * @param array<string, true>    $sharedHubs
     * @param array<string, string>  $boundary
     *
     * @return list<string>
     */
    private function legend(array $members, array $sortedEdges, array $sharedHubs, array $boundary, GenerationContext $ctx): array
    {
        $lines = ['legend bottom'];
        $lines[] = sprintf('  %d classes, %d edges', count($members), count($sortedEdges));

        if ($ctx->edgeColor !== 'none') {
            $lines[] = '  Edge color: ' . match ($ctx->edgeColor) {
                'source' => 'by source class',
                'pair' => 'by source/target pair',
                default => 'by target class',
            };
        }
        if ($sharedHubs !== []) {
            $lines[] = '  <<shared>>: hub duplicated here';
        }
        if ($boundary !== []) {
            $lines[] = '  <<external>>: defined in another cluster';
        }

        $lines[] = 'endlegend';

        return $lines;
    }

    /**
     * @param list<Relation> $edges
     *
     * @return list<Relation>
     */
    private function sortEdges(array $edges): array
    {
        usort($edges, static function (Relation $a, Relation $b): int {
            return strcmp($a->source, $b->source)
                ?: (strcmp($a->target, $b->target)
                    ?: (strcmp($a->arrow, $b->arrow)
                        ?: strcmp($a->label ?? '', $b->label ?? '')));
        });

        return $edges;
    }
}
