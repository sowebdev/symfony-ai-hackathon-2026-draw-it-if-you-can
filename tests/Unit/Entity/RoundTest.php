<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Round;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class RoundTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testThePlayerAndTheAiTakeTurns(): void
    {
        $game = new Game(Theme::Animals, Language::English, 6, DrawnBy::Ai);

        self::assertSame(
            [DrawnBy::Ai, DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player],
            array_map(static fn (int $number): DrawnBy => (new Round($game, $number, 'cat', 60_000))->getDrawnBy(), range(1, 6)),
        );
        self::assertTrue((new Round($game, 6, 'cat', 60_000))->isLast());
        self::assertFalse((new Round($game, 5, 'cat', 60_000))->isLast());
    }

    public function testARoundOutsideTheGameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Round(new Game(Theme::Animals, Language::English, 6), 7, 'cat', 60_000);
    }

    public function testTheRoundIsOverOnceTheGraceSecondIsGone(): void
    {
        $clock = self::mockTime();
        $round = new Round(new Game(Theme::Animals, Language::English), 1, 'cat', 60_000);

        $clock->sleep(59.5);
        self::assertSame(500, $round->remainingMs());
        self::assertFalse($round->isOver());

        $clock->sleep(1.0);
        self::assertSame(0, $round->remainingMs());
        self::assertFalse($round->isOver(), 'A word sent at the last second still counts');

        $clock->sleep(0.6);
        self::assertTrue($round->isTimeUp());
        self::assertTrue($round->isOver());
    }

    public function testAGuessSaidTooLateFindsNothing(): void
    {
        self::mockTime();
        $round = new Round(new Game(Theme::Animals, Language::English), 1, 'cat', 60_000);

        $round->markFound(new Guess($round, 'cat', 61_001));
        self::assertFalse($round->isFound());

        $round->markFound(new Guess($round, 'cat', 60_900));
        self::assertSame(60_900, $round->getFoundAfterMs());
    }

    public function testANearWordIsFoundWhenThePlayerValidatesIt(): void
    {
        self::mockTime();
        $round = new Round(new Game(Theme::Animals, Language::English), 1, 'cat', 60_000);
        $guess = new Guess($round, 'kitten', 5_000);

        $round->markFound($guess, 61_500);
        self::assertFalse($round->isFound(), 'Validated too late');

        $round->markFound($guess, 12_000);
        self::assertSame(12_000, $round->getFoundAfterMs());
        self::assertTrue($guess->isAccepted());
    }

    public function testNoGivingUpOnceTheTimeIsUp(): void
    {
        $clock = self::mockTime();
        $round = new Round(new Game(Theme::Animals, Language::English), 1, 'cat', 60_000);

        $clock->sleep(62);
        $round->giveUp();

        self::assertFalse($round->isGivenUp());
    }
}
