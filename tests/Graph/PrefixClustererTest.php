<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Cluster;
use PumlSplitter\Graph\PrefixClusterer;

#[CoversClass(PrefixClusterer::class)]
#[CoversClass(Cluster::class)]
final class PrefixClustererTest extends TestCase
{
    public function testGroupsByFirstCamelCaseToken(): void
    {
        $clusterer = new PrefixClusterer(
            $this->identityNames(['OrderLine', 'OrderHeader', 'InvoiceLine', 'Customer']),
            maxSize: 10,
        );

        $clusters = $clusterer->cluster(['OrderLine', 'OrderHeader', 'InvoiceLine', 'Customer']);

        self::assertSame(['Customer', 'Invoice', 'Order'], array_map(static fn (Cluster $c): string => $c->name, $clusters));
        self::assertSame(['OrderHeader', 'OrderLine'], $clusters[2]->members);
    }

    public function testSubdividesOversizedGroupByTwoTokens(): void
    {
        $names = ['OrderLineA', 'OrderLineB', 'OrderHeaderX'];
        $clusterer = new PrefixClusterer($this->identityNames($names), maxSize: 2);

        $clusters = $clusterer->cluster($names);

        self::assertSame(['OrderHeader', 'OrderLine'], array_map(static fn (Cluster $c): string => $c->name, $clusters));
        self::assertSame(['OrderLineA', 'OrderLineB'], $clusters[1]->members);
    }

    public function testKeepsGroupWholeWhenTwoTokenSplitDegenerates(): void
    {
        // All names share the first token "Order" but have distinct second
        // tokens, so no meaningful sub-prefix exists: keep the group whole.
        $names = ['OrderA', 'OrderB', 'OrderC'];
        $clusterer = new PrefixClusterer($this->identityNames($names), maxSize: 2);

        $clusters = $clusterer->cluster($names);

        self::assertCount(1, $clusters);
        self::assertSame('Order', $clusters[0]->name);
        self::assertSame(['OrderA', 'OrderB', 'OrderC'], $clusters[0]->members);
    }

    public function testKeepsAnonymizedTokenNamesNonDegenerate(): void
    {
        // Token-anonymized names (Tok001Tok002…) must group by their real first
        // token, not collapse under a shared "Tok" prefix.
        $names = ['Tok001Tok009', 'Tok001Tok010', 'Tok002Tok011'];
        $clusterer = new PrefixClusterer($this->identityNames($names), maxSize: 10);

        $clusters = $clusterer->cluster($names);

        self::assertSame(['Tok001', 'Tok002'], array_map(static fn (Cluster $c): string => $c->name, $clusters));
        self::assertSame(['Tok001Tok009', 'Tok001Tok010'], $clusters[0]->members);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function identityNames(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = $name;
        }

        return $map;
    }
}
