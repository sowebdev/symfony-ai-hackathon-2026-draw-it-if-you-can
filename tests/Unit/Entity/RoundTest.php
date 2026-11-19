<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Round;
use App\Game\Author;
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
        $game = new Game(Language::English, 6, DrawnBy::Ai);

        self::assertSame(
            [DrawnBy::Ai, DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player],
            array_map(static fn (int $number): DrawnBy => (new Round($game, $number, 'cat', 60_000, Theme::Animals))->getDrawnBy(), range(1, 6)),
        );
        self::assertTrue((new Round($game, 6, 'cat', 60_000, Theme::Animals))->isLast());
        self::assertFalse((new Round($game, 5, 'cat', 60_000, Theme::Animals))->isLast());
    }

    public function testARoundOutsideTheGameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Round(new Game(Language::English, 6), 7, 'cat', 60_000, Theme::Animals);
    }

    public function testTheWordIsShownWithItsArticleButCountsWithout(): void
    {
        $game = new Game(Language::French);

        $round = new Round($game, 1, 'château fort', 60_000, Theme::Places, 'un');
        self::assertSame('un château fort', $round->getTargetPhrase());
        self::assertSame('château fort', $round->getTargetWord());
        self::assertSame("un'amica", (new Round($game, 1, 'amica', 60_000, Theme::Places, "un'"))->getTargetPhrase());
        self::assertSame('chat', (new Round($game, 1, 'chat', 60_000, Theme::Animals))->getTargetPhrase(), 'A round without article');
    }

    public function testTheRoundIsOverOnceTheGraceSecondIsGone(): void
    {
        $clock = self::mockTime();
        $round = new Round(new Game(Language::English), 1, 'cat', 60_000, Theme::Animals);

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

    public function testTheTimeLeftStopsWhenTheRoundEnds(): void
    {
        $clock = self::mockTime();
        $round = new Round(new Game(Language::English), 1, 'cat', 60_000, Theme::Animals);
        $clock->sleep(43);
        self::assertSame(17_000, $round->timeLeftMs());

        $round->markFound(new Guess($round, 'cat', 43_000, Author::Player));
        $clock->sleep(16);
        self::assertSame(17_000, $round->timeLeftMs(), 'Found with 17 s left');
        self::assertSame(1_000, $round->remainingMs(), 'The clock of the round itself goes on');

        $lost = new Round(new Game(Language::English), 1, 'cat', 60_000, Theme::Animals);
        $clock->sleep(62);
        self::assertSame(0, $lost->timeLeftMs());
    }

    public function testAGuessSaidTooLateFindsNothing(): void
    {
        self::mockTime();
        $round = new Round(new Game(Language::English), 1, 'cat', 60_000, Theme::Animals);

        $round->markFound(new Guess($round, 'cat', 61_001, Author::Player));
        self::assertFalse($round->isFound());

        $round->markFound(new Guess($round, 'cat', 60_900, Author::Player));
        self::assertSame(60_900, $round->getFoundAfterMs());
    }

    public function testNoGivingUpOnceTheTimeIsUp(): void
    {
        $clock = self::mockTime();
        $round = new Round(new Game(Language::English), 1, 'cat', 60_000, Theme::Animals);

        $clock->sleep(62);
        $round->giveUp();

        self::assertFalse($round->isGivenUp());
    }
}
