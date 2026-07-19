<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Outcome of {@see SeedsPartitioner::partition()}: either a {@see Partition},
 * or a fatal error (plan §6ter: an unknown `--seed` alias, or zero seeds
 * available).
 */
final readonly class SeedsPartitionResult extends FatalOrResult
{
    public function __construct(
        public ?Partition $partition,
        ?string $fatalError,
    ) {
        parent::__construct($fatalError);
    }
}
