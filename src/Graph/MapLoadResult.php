<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Outcome of {@see MapFileLoader::load()}: either a validated {@see MapFile},
 * or a precise fatal error message (plan §6ter: "JSON invalide ou structure
 * inattendue → erreur fatale avec message précis").
 */
final readonly class MapLoadResult extends FatalOrResult
{
    public function __construct(
        public ?MapFile $map,
        ?string $fatalError,
    ) {
        parent::__construct($fatalError);
    }
}
