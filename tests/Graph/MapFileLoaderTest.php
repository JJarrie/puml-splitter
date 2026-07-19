<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\MapFile;
use PumlSplitter\Graph\MapFileLoader;

#[CoversClass(MapFileLoader::class)]
#[CoversClass(MapFile::class)]
final class MapFileLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/puml-map-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
    }

    private function write(string $content): string
    {
        $path = $this->dir . '/map.json';
        file_put_contents($path, $content);

        return $path;
    }

    public function testLoadsAValidMap(): void
    {
        $path = $this->write('{"clusters": {"evenement": ["A", "B"], "piece": ["C"]}, "fallback": "misc"}');

        $result = (new MapFileLoader())->load($path);

        self::assertFalse($result->isFatal());
        self::assertNotNull($result->map);
        self::assertSame(['evenement' => ['A', 'B'], 'piece' => ['C']], $result->map->clusters);
        self::assertSame('misc', $result->map->fallback);
    }

    public function testFallbackDefaultsToAutoWhenOmitted(): void
    {
        $path = $this->write('{"clusters": {"a": ["X"]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertFalse($result->isFatal());
        self::assertSame(MapFile::FALLBACK_AUTO, $result->map?->fallback);
    }

    public function testMissingFileIsFatal(): void
    {
        $result = (new MapFileLoader())->load($this->dir . '/does-not-exist.json');

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('Cannot read map file', (string) $result->fatalError);
    }

    public function testInvalidJsonIsFatal(): void
    {
        $path = $this->write('{not valid json');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('Invalid JSON', (string) $result->fatalError);
    }

    public function testMissingClustersKeyIsFatal(): void
    {
        $path = $this->write('{"fallback": "auto"}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('"clusters"', (string) $result->fatalError);
    }

    public function testClustersMustBeAnObjectNotAList(): void
    {
        $path = $this->write('{"clusters": ["A", "B"]}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
    }

    public function testClusterValueMustBeAListOfStrings(): void
    {
        $path = $this->write('{"clusters": {"a": "not-a-list"}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('cluster "a"', (string) $result->fatalError);
    }

    public function testClusterAliasMustBeANonEmptyString(): void
    {
        $path = $this->write('{"clusters": {"a": [123]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
    }

    public function testInvalidFallbackValueIsFatal(): void
    {
        $path = $this->write('{"clusters": {"a": ["X"]}, "fallback": "bogus"}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('bogus', (string) $result->fatalError);
    }

    public function testDuplicateAliasAcrossClustersIsFatal(): void
    {
        $path = $this->write('{"clusters": {"a": ["X", "Y"], "b": ["Y", "Z"]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('Y', (string) $result->fatalError);
    }

    public function testDuplicateAliasErrorListsAllDuplicatesNotJustTheFirst(): void
    {
        $path = $this->write('{"clusters": {"a": ["X", "Y"], "b": ["X", "Y"]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('X', (string) $result->fatalError);
        self::assertStringContainsString('Y', (string) $result->fatalError);
    }

    public function testEmptyClustersObjectIsAcceptedNotRejected(): void
    {
        // A pure-fallback map ({"clusters": {}}) is legitimate, and is exactly
        // what --emit-map produces for a Partition with zero clusters — must
        // round-trip, not be rejected as malformed.
        $path = $this->write('{"clusters": {}, "fallback": "misc"}');

        $result = (new MapFileLoader())->load($path);

        self::assertFalse($result->isFatal());
        self::assertSame([], $result->map?->clusters);
    }

    public function testRepeatedAliasWithinOneClusterGetsAPreciseMessageNotACrossClusterOne(): void
    {
        $path = $this->write('{"clusters": {"a": ["X", "X", "Y"]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('within one cluster', (string) $result->fatalError);
        self::assertStringNotContainsString('more than one cluster', (string) $result->fatalError);
    }

    public function testClusterNameThatNormalizesToAnEmptySlugIsFatal(): void
    {
        $path = $this->write('{"clusters": {"!!!": ["X"]}}');

        $result = (new MapFileLoader())->load($path);

        self::assertTrue($result->isFatal());
        self::assertStringContainsString('empty slug', (string) $result->fatalError);
    }
}
