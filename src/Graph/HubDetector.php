<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Classifies nodes as hubs by in-degree, out-degree or an explicit `--hub` flag,
 * and resolves the policy that applies to each (global, per-hub override, or the
 * differentiated `separate` default for out-only hubs). See plan §6.1 and §6.5.
 */
final class HubDetector
{
    /**
     * @param list<string>              $forced    aliases forced to hub status
     * @param array<string, HubPolicy>  $overrides per-alias policy overrides
     * @param list<string>              $excluded  aliases that can never be a hub
     *                                             (plan §6ter: a `--strategy=map`
     *                                             assignment takes priority over
     *                                             degree/forced hub detection —
     *                                             the human map wins even over
     *                                             an explicit `--hub=ALIAS`)
     */
    public function __construct(
        private readonly int $inThreshold,
        private readonly int $outThreshold,
        private readonly array $forced,
        private readonly HubPolicy $globalPolicy,
        private readonly array $overrides = [],
        private readonly array $excluded = [],
    ) {
    }

    /**
     * @return list<Hub> detected hubs, sorted by alias
     */
    public function detect(Graph $graph): array
    {
        $forced = array_fill_keys($this->forced, true);
        $excluded = array_fill_keys($this->excluded, true);

        $hubs = [];
        foreach ($graph->nodes() as $alias) {
            if (isset($excluded[$alias])) {
                continue;
            }

            $inDegree = $graph->inDegree($alias);
            $outDegree = $graph->outDegree($alias);

            $byIn = $inDegree >= $this->inThreshold;
            $byOut = $outDegree >= $this->outThreshold;
            $isForced = isset($forced[$alias]);

            if (!$byIn && !$byOut && !$isForced) {
                continue;
            }

            // A forced hub reports `forced` even when it also crosses a
            // threshold: the salient fact is that the user pinned it (§8).
            $reason = match (true) {
                $isForced => HubReason::Forced,
                $byIn && $byOut => HubReason::Both,
                $byIn => HubReason::In,
                default => HubReason::Out,
            };

            $hubs[] = new Hub(
                alias: $alias,
                inDegree: $inDegree,
                outDegree: $outDegree,
                reason: $reason,
                forced: $isForced,
                policy: $this->resolvePolicy($alias, $reason, $isForced),
            );
        }

        return $hubs;
    }

    private function resolvePolicy(string $alias, HubReason $reason, bool $forced): HubPolicy
    {
        if (isset($this->overrides[$alias])) {
            return $this->overrides[$alias];
        }

        // Differentiated default: an out-only hub would re-introduce dozens of
        // edges into every cluster if duplicated, so it goes to `separate`.
        if ($reason === HubReason::Out && !$forced) {
            return HubPolicy::Separate;
        }

        return $this->globalPolicy;
    }
}
