<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * A validated `--strategy=map` partition file (plan §6ter): named clusters →
 * alias lists, plus how to treat graph nodes the map doesn't mention.
 */
final readonly class MapFile
{
    public const FALLBACK_AUTO = 'auto';
    public const FALLBACK_MISC = 'misc';
    public const FALLBACK_ERROR = 'error';

    /**
     * @param array<string, list<string>> $clusters cluster name (as given in the
     *                                               file, unmodified) => alias list
     */
    public function __construct(
        public array $clusters,
        public string $fallback,
    ) {
    }

    /**
     * @return array<string, string> alias => the cluster name that claims it
     */
    public function aliasOwners(): array
    {
        $owners = [];
        foreach ($this->clusters as $name => $aliases) {
            foreach ($aliases as $alias) {
                $owners[$alias] = $name;
            }
        }

        return $owners;
    }
}
