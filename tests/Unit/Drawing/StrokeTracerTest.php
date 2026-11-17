<?php

namespace App\Tests\Unit\Drawing;

use App\Drawing\GridStroke;
use App\Drawing\StrokePoints;
use App\Drawing\StrokeTracer;
use PHPUnit\Framework\TestCase;

final class StrokeTracerTest extends TestCase
{
    private static function stroke(string $cells): GridStroke
    {
        return GridStroke::fromWritten('test', $cells) ?? throw new \LogicException('No cell');
    }

    public function testTheSameSeedGivesTheSameStroke(): void
    {
        $stroke = self::stroke('x5y5,x25y10,x45y45');

        self::assertSame((new StrokeTracer())->draw($stroke, 0, 7)->points, (new StrokeTracer())->draw($stroke, 0, 7)->points);
        self::assertNotSame((new StrokeTracer())->draw($stroke, 0, 7)->points, (new StrokeTracer())->draw($stroke, 0, 8)->points);
    }

    public function testFollowsTheCellsFromStartToEnd(): void
    {
        $points = (new StrokeTracer())->draw(self::stroke('x1y1,x25y25,x50y50'), 0, 1)->points;

        // Cell centres are at (n - 0.5) * 20; the wobble and the overshoot stay small.
        self::assertEqualsWithDelta(10, $points[0][0], 6);
        self::assertEqualsWithDelta(10, $points[0][1], 6);
        self::assertEqualsWithDelta(990, end($points)[0], 16);
        self::assertEqualsWithDelta(990, end($points)[1], 16);
        self::assertGreaterThan(50, \count($points), 'A smooth line, not three dots');
    }

    public function testStaysOnTheCanvasAndGoesForwardInTime(): void
    {
        foreach (range(1, 30) as $seed) {
            $points = (new StrokeTracer())->draw(self::stroke('x1y1,x50y1,x50y1,x50y50,x1y50,x1y1'), 5000, $seed)->points;

            self::assertSame(5000, $points[0][2]);
            $previous = 5000;
            foreach ($points as [$x, $y, $t]) {
                self::assertGreaterThanOrEqual(0, min($x, $y));
                self::assertLessThanOrEqual(StrokePoints::CANVAS_SIZE, max($x, $y));
                self::assertGreaterThanOrEqual($previous, $t);
                $previous = $t;
            }
        }
    }

    public function testARepeatedCellIsASharpCorner(): void
    {
        $points = (new StrokeTracer())->draw(self::stroke('x5y5,x45y5,x45y5,x45y45'), 0, 3)->points;

        // The line goes through the corner cell (890, 90), instead of cutting across.
        $closest = min(array_map(static fn (array $p): float => hypot($p[0] - 890, $p[1] - 90), $points));
        self::assertLessThan(8, $closest);
    }

    public function testThePenTakesAboutASecondAcrossTheCanvas(): void
    {
        $points = (new StrokeTracer())->draw(self::stroke('x1y25,x50y25'), 0, 1);

        self::assertGreaterThan(800, StrokeTracer::durationMs($points));
        self::assertLessThan(1500, StrokeTracer::durationMs($points));
    }

    public function testASingleCellIsADot(): void
    {
        $points = (new StrokeTracer())->draw(self::stroke('x25y25'), 100, 1)->points;

        self::assertCount(1, $points);
        self::assertSame([490, 490, 100], $points[0]);
    }

    public function testWithoutHumanizingTheStrokeGoesStraightFromCellToCell(): void
    {
        $stroke = self::stroke('x1y1,x50y1,x50y1,x50y50');
        $tracer = new StrokeTracer(humanized: false);
        $points = $tracer->draw($stroke, 0, 1)->points;

        self::assertSame($points, $tracer->draw($stroke, 0, 99)->points, 'The seed does not matter');
        self::assertSame([10, 10, 0], $points[0]);
        self::assertSame([990, 990], \array_slice(end($points), 0, 2), 'No overshoot');
        foreach ($points as [$x, $y]) {
            // On the top edge or on the right edge: no wobble, no curve.
            self::assertTrue(10 === $y || 990 === $x, "[$x, $y] is off the segments");
        }
        // A steady pen: 1960 canvas units at 0.9 units per millisecond.
        self::assertEqualsWithDelta(2178, StrokeTracer::durationMs($tracer->draw($stroke, 0, 1)), 2);
    }
}
