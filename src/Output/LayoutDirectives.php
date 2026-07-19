<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * Layout-engine header lines (plan §7bis), injected before user `--header`
 * lines so the latter stay authoritative on any conflict.
 */
final class LayoutDirectives
{
    /**
     * @return list<string>
     */
    public static function forLayout(string $layout): array
    {
        return match ($layout) {
            'elk' => ['!pragma layout elk'],
            'graphviz' => ['skinparam linetype polyline', 'skinparam nodesep 20', 'skinparam ranksep 30'],
            default => [],
        };
    }
}
