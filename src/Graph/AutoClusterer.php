<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * The `auto` strategy (plan §6.3): compute both prefix and louvain, keep the one
 * that minimises inter-cluster (cut) edges among the candidates that satisfy the
 * size constraint; ties, and the "neither/both satisfy" cases, resolve to prefix.
 * Each decision is recorded for the dry-run report.
 */
final class AutoClusterer implements Clusterer
{
    /** @var list<AutoDecision> */
    private array $decisions = [];

    public function __construct(
        private readonly PrefixClusterer $prefix,
        private readonly LouvainClusterer $louvain,
        private readonly Graph $graph,
        private readonly int $maxSize,
    ) {
    }

    public function cluster(array $members): array
    {
        $prefixClusters = $this->prefix->cluster($members);
        $louvainClusters = $this->louvain->cluster($members);

        $prefixCut = $this->cutEdges($prefixClusters, $members);
        $louvainCut = $this->cutEdges($louvainClusters, $members);
        $prefixSatisfies = $this->satisfiesSize($prefixClusters);
        $louvainSatisfies = $this->satisfiesSize($louvainClusters);

        $chooseLouvain = $this->preferLouvain($prefixSatisfies, $prefixCut, $louvainSatisfies, $louvainCut);

        $this->decisions[] = new AutoDecision(
            chosen: $chooseLouvain ? 'louvain' : 'prefix',
            size: count($members),
            prefixCut: $prefixCut,
            louvainCut: $louvainCut,
            prefixSatisfies: $prefixSatisfies,
            louvainSatisfies: $louvainSatisfies,
        );

        return $chooseLouvain ? $louvainClusters : $prefixClusters;
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

    private function preferLouvain(bool $prefixSatisfies, int $prefixCut, bool $louvainSatisfies, int $louvainCut): bool
    {
        // A candidate that satisfies the size constraint always beats one that does not.
        if ($prefixSatisfies !== $louvainSatisfies) {
            return $louvainSatisfies;
        }

        // Otherwise fewer cut edges wins; a tie resolves to prefix.
        return $louvainCut < $prefixCut;
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
