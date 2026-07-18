<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PumlSplitter\Graph\Hub;
use PumlSplitter\Graph\HubDetector;
use PumlSplitter\Graph\HubPolicy;
use PumlSplitter\Graph\HubReason;
use PumlSplitter\Tests\Support\GraphFactory;

#[CoversClass(HubDetector::class)]
#[CoversClass(Hub::class)]
final class HubDetectorTest extends TestCase
{
    /**
     * A single fixture exercising every classification path:
     *  - A: in-degree hub
     *  - B: out-degree hub, overridden to `exclude`
     *  - C: mixed (in and out) hub
     *  - E: forced hub with sub-threshold degrees
     *  - F: out-degree hub → differentiated `separate` default
     *  - G: forced hub that also crosses the in-degree threshold
     *
     * @return array<string, Hub>
     */
    private function detect(): array
    {
        $graph = GraphFactory::fromEdges([
            ['X1', 'A'], ['X2', 'A'], ['X3', 'A'],
            ['B', 'W1'], ['B', 'W2'], ['B', 'W3'],
            ['Z1', 'C'], ['Z2', 'C'], ['Z3', 'C'], ['C', 'V1'], ['C', 'V2'], ['C', 'V3'],
            ['E', 'P'],
            ['F', 'Y1'], ['F', 'Y2'], ['F', 'Y3'],
            ['X4', 'G'], ['X5', 'G'], ['X6', 'G'],
            ['D', 'Q'],
        ]);

        $detector = new HubDetector(
            inThreshold: 3,
            outThreshold: 3,
            forced: ['E', 'G'],
            globalPolicy: HubPolicy::Duplicate,
            overrides: ['B' => HubPolicy::Exclude],
        );

        $hubs = [];
        foreach ($detector->detect($graph) as $hub) {
            $hubs[$hub->alias] = $hub;
        }

        return $hubs;
    }

    public function testDetectsExactlyTheExpectedHubs(): void
    {
        self::assertSame(['A', 'B', 'C', 'E', 'F', 'G'], array_keys($this->detect()));
    }

    public function testForcedHubTakesPrecedenceOverThresholdReason(): void
    {
        $hub = $this->detect()['G'];

        self::assertSame(HubReason::Forced, $hub->reason);
        self::assertTrue($hub->forced);
        self::assertSame(3, $hub->inDegree);
        self::assertSame(HubPolicy::Duplicate, $hub->policy);
    }

    public function testInDegreeHub(): void
    {
        $hub = $this->detect()['A'];

        self::assertSame(HubReason::In, $hub->reason);
        self::assertSame(3, $hub->inDegree);
        self::assertFalse($hub->forced);
        self::assertSame(HubPolicy::Duplicate, $hub->policy);
    }

    public function testMixedHubFollowsGlobalPolicy(): void
    {
        $hub = $this->detect()['C'];

        self::assertSame(HubReason::Both, $hub->reason);
        self::assertSame(HubPolicy::Duplicate, $hub->policy);
        self::assertFalse($hub->isPureOut());
    }

    public function testForcedHubWithLowDegree(): void
    {
        $hub = $this->detect()['E'];

        self::assertSame(HubReason::Forced, $hub->reason);
        self::assertTrue($hub->forced);
        self::assertSame(HubPolicy::Duplicate, $hub->policy);
    }

    public function testPureOutHubGetsSeparateDefault(): void
    {
        $hub = $this->detect()['F'];

        self::assertSame(HubReason::Out, $hub->reason);
        self::assertTrue($hub->isPureOut());
        self::assertSame(HubPolicy::Separate, $hub->policy);
    }

    public function testOverrideBeatsDifferentiatedDefault(): void
    {
        $hub = $this->detect()['B'];

        self::assertSame(HubReason::Out, $hub->reason);
        self::assertSame(HubPolicy::Exclude, $hub->policy);
    }

    public function testNonHubsAreExcluded(): void
    {
        $hubs = $this->detect();

        self::assertArrayNotHasKey('D', $hubs);
        self::assertArrayNotHasKey('X1', $hubs);
    }
}
