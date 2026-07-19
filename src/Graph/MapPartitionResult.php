<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Outcome of {@see MapPartitioner::partition()}: either a {@see Partition}, or
 * a fatal error (plan §6ter `fallback=error`, or a map alias unknown to the
 * graph while `fallback=error`).
 */
final readonly class MapPartitionResult extends FatalOrResult
{
    public function __construct(
        public ?Partition $partition,
        ?string $fatalError,
    ) {
        parent::__construct($fatalError);
    }
}
