<?php

namespace App\Tests\Unit\Game;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Round;
use App\Game\Author;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\ScoreGauge;
use App\Game\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreGaugeTest extends TestCase
{
    public function testInADuelEachSideHasTheWordsItFound(): void
    {
        $game = new Game(Language::English, 6, DrawnBy::Ai, Mode::Duel);
        $byPlayer = new Round($game, 1, 'cat', 60_000, Theme::Animals);
        $byPlayer->markFound(new Guess($byPlayer, 'cat', 0, Author::Player));
        $byAi = new Round($game, 2, 'dog', 60_000, Theme::Animals);
        $byAi->markFound(new Guess($byAi, 'dog', 35_000, Author::Ai));
        $byRival = new Round($game, 3, 'cow', 60_000, Theme::Animals);
        $byRival->markFound(new Guess($byRival, 'cow', 60_000, Author::Agent));
        $nobody = new Round($game, 4, 'owl', 60_000, Theme::Animals);
        $gauge = new ScoreGauge(1000, 10, 100);

        self::assertSame(1550, $gauge->total([$byPlayer, $byAi, $byRival, $nobody]), 'The player and the AI play together');
        self::assertSame(100, $gauge->rivalTotal([$byPlayer, $byAi, $byRival, $nobody]));
    }

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

    public function testTheGaugeFollowsTheLengthOfTheRound(): void
    {
        $gauge = new ScoreGauge(1000, 10, 100);

        self::assertSame([[0, 1000], [10_000, 1000], [30_000, 100]], $gauge->steps(30_000));
        self::assertSame(1000, $gauge->points(10_000, 30_000));
        self::assertSame(550, $gauge->points(20_000, 30_000));
        self::assertSame(100, $gauge->points(30_000, 30_000));
        self::assertSame(0, $gauge->points(31_001, 30_000));

        self::assertSame([[0, 1000], [10_000, 1000], [120_000, 100]], $gauge->steps(120_000));
        self::assertSame(1000, $gauge->points(10_000, 120_000));
        self::assertSame(550, $gauge->points(65_000, 120_000));
        self::assertSame(100, $gauge->points(120_000, 120_000));
        self::assertSame(100, $gauge->points(121_000, 120_000));
        self::assertSame(0, $gauge->points(121_001, 120_000));
    }
}
