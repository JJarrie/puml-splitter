<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * A node pulled out of the clustering graph because of its degree or an explicit
 * `--hub` flag, together with the resolved policy that will apply to it.
 */
final readonly class Hub
{
    public function __construct(
        public string $alias,
        public int $inDegree,
        public int $outDegree,
        public HubReason $reason,
        public bool $forced,
        public HubPolicy $policy,
    ) {
    }

    /**
     * True when the node is a hub solely because of its out-degree (not its
     * in-degree and not a forced flag) — the case that receives the
     * differentiated `separate` default (plan §6.5).
     */
    public function isPureOut(): bool
    {
        return $this->reason === HubReason::Out && !$this->forced;
    }
}
