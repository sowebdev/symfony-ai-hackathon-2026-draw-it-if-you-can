<?php

namespace App\Tests\Unit\Drawing;

use App\Drawing\InvalidStroke;
use App\Drawing\StrokePoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrokePointsTest extends TestCase
{
    public function testAcceptsAValidStroke(): void
    {
        $stroke = StrokePoints::fromArray([[0, 0, 120], [500, 500, 150], [1000, 1000, 150]]);

        self::assertSame([[0, 0, 120], [500, 500, 150], [1000, 1000, 150]], $stroke->points);
        self::assertSame(120, $stroke->startTime());
        self::assertSame(150, $stroke->endTime());
    }

    public function testAcceptsASinglePoint(): void
    {
        self::assertCount(1, StrokePoints::fromArray([[10, 20, 0]])->points);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStrokes(): iterable
    {
        yield 'not a list' => ['points'];
        yield 'keyed array' => [['a' => [1, 2, 3]]];
        yield 'empty' => [[]];
        yield 'too many points' => [array_fill(0, StrokePoints::MAX_POINTS + 1, [1, 1, 1])];
        yield 'point is not an array' => [[42]];
        yield 'point with two values' => [[[1, 2]]];
        yield 'point with four values' => [[[1, 2, 3, 4]]];
        yield 'point with keys' => [[['x' => 1, 'y' => 2, 't' => 3]]];
        yield 'float coordinate' => [[[1.5, 2, 3]]];
        yield 'string coordinate' => [[['1', 2, 3]]];
        yield 'negative x' => [[[-1, 2, 3]]];
        yield 'y beyond the canvas' => [[[1, 1001, 3]]];
        yield 'negative time' => [[[1, 2, -1]]];
        yield 'time going back' => [[[1, 2, 100], [3, 4, 99]]];
    }

    #[DataProvider('invalidStrokes')]
    public function testRejectsAnInvalidStroke(mixed $points): void
    {
        $this->expectException(InvalidStroke::class);

        StrokePoints::fromArray($points);
    }
}
