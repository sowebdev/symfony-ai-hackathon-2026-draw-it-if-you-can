<?php

namespace App\Tests\Unit\Game;

use App\Game\ScoreGauge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreGaugeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function times(): iterable
    {
        yield 'at once' => [0, 1000];
        yield 'still full at 10 s' => [10_000, 1000];
        yield 'halfway down' => [35_000, 550];
        yield 'rounded to ten' => [11_000, 980];
        yield 'the fewest at the end' => [60_000, 100];
        yield 'the fewest in the grace second' => [60_900, 100];
        yield 'nothing after the grace second' => [61_001, 0];
    }

    #[DataProvider('times')]
    public function testTheGaugeEmptiesWithTime(int $foundAfterMs, int $points): void
    {
        self::assertSame($points, (new ScoreGauge(1000, 10, 100))->points($foundAfterMs, 60_000));
    }

    public function testTheBrowserGetsTheCornersOfTheGauge(): void
    {
        self::assertSame([[0, 1000], [10_000, 1000], [60_000, 100]], (new ScoreGauge(1000, 10, 100))->steps(60_000));
    }

    public function testInAShortRoundTheGaugeIsFullForHalfOfItAtMost(): void
    {
        $gauge = new ScoreGauge(1000, 10, 100);

        self::assertSame([[0, 1000], [2500, 1000], [5000, 100]], $gauge->steps(5000));
        self::assertSame(1000, $gauge->points(2500, 5000));
        self::assertSame(100, $gauge->points(5000, 5000));
    }
}
