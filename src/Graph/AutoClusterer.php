<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * The `auto` strategy (plan §6.3, §6ter): compute both prefix and leiden, keep
 * the one that minimises inter-cluster (cut) edges among the candidates that
 * satisfy the size constraint; ties, and the "neither/both satisfy" cases,
 * resolve to prefix. Leiden replaced louvain in this comparison at M9 — louvain
 * remains available directly via `--strategy=louvain`, just no longer compared
 * here. Each decision is recorded for the dry-run report.
 */
final class AutoClusterer implements Clusterer
{
    /** @var list<AutoDecision> */
    private array $decisions = [];

    public function __construct(
        private readonly PrefixClusterer $prefix,
        private readonly Clusterer $leiden,
        private readonly Graph $graph,
        private readonly int $maxSize,
    ) {
    }

    public function cluster(array $members): array
    {
        $prefixClusters = $this->prefix->cluster($members);
        $leidenClusters = $this->leiden->cluster($members);

        $prefixCut = $this->cutEdges($prefixClusters, $members);
        $leidenCut = $this->cutEdges($leidenClusters, $members);
        $prefixSatisfies = $this->satisfiesSize($prefixClusters);
        $leidenSatisfies = $this->satisfiesSize($leidenClusters);

        $chooseLeiden = $this->preferLeiden($prefixSatisfies, $prefixCut, $leidenSatisfies, $leidenCut);

        $this->decisions[] = new AutoDecision(
            chosen: $chooseLeiden ? 'leiden' : 'prefix',
            size: count($members),
            prefixCut: $prefixCut,
            leidenCut: $leidenCut,
            prefixSatisfies: $prefixSatisfies,
            leidenSatisfies: $leidenSatisfies,
        );

        return $chooseLeiden ? $leidenClusters : $prefixClusters;
    }

    /**
     * @return list<AutoDecision>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * Retracts the decision recorded by the most recent {@see cluster()} call,
     * for a caller (the refiner) that ends up rejecting that split.
     */
    public function discardLastDecision(): void
    {
        array_pop($this->decisions);
    }

    /**
     * Clears every recorded decision, for a caller ({@see ClusterRefiner})
     * about to re-run {@see cluster()} on a reshaped cluster set: decisions
     * from a superseded round shouldn't linger in the dry-run report
     * alongside the round that actually produced the final clusters.
     */
    public function resetDecisions(): void
    {
        $this->decisions = [];
    }

    private function preferLeiden(bool $prefixSatisfies, int $prefixCut, bool $leidenSatisfies, int $leidenCut): bool
    {
        // A candidate that satisfies the size constraint always beats one that does not.
        if ($prefixSatisfies !== $leidenSatisfies) {
            return $leidenSatisfies;
        }

        // Otherwise fewer cut edges wins; a tie resolves to prefix.
        return $leidenCut < $prefixCut;
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function satisfiesSize(array $clusters): bool
    {
        foreach ($clusters as $cluster) {
            if ($cluster->size() > $this->maxSize) {
                return false;
            }
        }

        return true;
    }

    /**
     * Counts undirected edges (within the induced sub-graph) whose endpoints land
     * in different clusters of the candidate.
     *
     * @param list<Cluster> $clusters
     * @param list<string>  $members
     */
    private function cutEdges(array $clusters, array $members): int
    {
        $clusterOf = [];
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster->members as $member) {
                $clusterOf[$member] = $index;
            }
        }
        $memberSet = array_fill_keys($members, true);

        $cut = 0;
        foreach ($members as $u) {
            foreach ($this->graph->neighbours($u) as $v) {
                if (!isset($memberSet[$v]) || strcmp($u, $v) >= 0) {
                    continue; // each undirected edge counted once, both endpoints in members
                }
                if (($clusterOf[$u] ?? -1) !== ($clusterOf[$v] ?? -2)) {
                    $cut++;
                }
            }
        }

        return $cut;
    }
}
