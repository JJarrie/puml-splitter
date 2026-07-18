<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Puml\Model\ClassKind;
use PumlSplitter\Puml\Model\Relation;
use PumlSplitter\Puml\Writer;

/**
 * Emits the `.puml` for one cluster file (plan §7): original + extra headers,
 * full member declarations (byte-identical bodies), referenced hubs rendered per
 * their resolved policy, and boundary `<<external: …>>` stub nodes. An edge is
 * emitted only if it touches a member of this file, which also guarantees no edge
 * between two external nodes is ever written.
 */
final class ClusterPumlGenerator
{
    private const SHARED_COLOR = '#LightYellow';
    private const EXTERNAL_COLOR = '#DDDDDD';

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

        foreach ($this->sortEdges($edges) as $relation) {
            $lines[] = $this->writer->relation($relation);
        }

        $lines[] = '@enduml';

        return implode("\n", $lines) . "\n";
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
        $class = $ctx->document->getClass($alias);
        if ($class === null) {
            return [$this->writer->stub(ClassKind::Clazz, $alias, $alias, '<<shared>>', self::SHARED_COLOR)];
        }

        return $this->writer->declaration($class, '<<shared>>', self::SHARED_COLOR);
    }

    private function declareExternal(string $alias, string $label, GenerationContext $ctx): string
    {
        $class = $ctx->document->getClass($alias);
        $name = $class !== null ? $class->name : $alias;
        $kind = $class !== null ? $class->kind : ClassKind::Clazz;

        return $this->writer->stub($kind, $name, $alias, '<<' . $label . '>>', self::EXTERNAL_COLOR);
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
