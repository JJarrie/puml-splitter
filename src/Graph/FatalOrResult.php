<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Shared shape for "either a result, or a precise fatal-error message"
 * (plan §6ter map-loading/partitioning outcomes): a single place enforcing
 * that invariant, rather than each caller re-deriving its own
 * `isFatal()`/nullable-payload pairing by hand.
 */
abstract readonly class FatalOrResult
{
    public function __construct(public ?string $fatalError)
    {
    }

    public function isFatal(): bool
    {
        return $this->fatalError !== null;
    }
}
