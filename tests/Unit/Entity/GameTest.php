<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use App\Game\Turns;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testAnAgentMayHaveNoName(): void
    {
        $game = new Game(Language::English, 6, DrawnBy::Ai, Mode::Copilot);
        $game->agentSeen(' ', new \DateTimeImmutable());

        self::assertNull($game->getAgentName());
        self::assertNull($game->getAgentDisplayName());
        self::assertTrue($game->isAgentConnected());
        self::assertTrue($game->isWithAgent());
        self::assertFalse($game->isDuel());
    }

    public function testWhoDrawsEachRoundForEachChoice(): void
    {
        $turns = new Game(Language::English, 6, DrawnBy::Ai, Mode::Solo, Turns::Alternate);
        self::assertSame([DrawnBy::Ai, DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player], array_map($turns->drawnByInRound(...), [1, 2, 3, 4]));

        $turns = new Game(Language::English, 6, DrawnBy::Player, Mode::Solo, Turns::Alternate);
        self::assertSame([DrawnBy::Player, DrawnBy::Ai, DrawnBy::Player], array_map($turns->drawnByInRound(...), [1, 2, 3]));

        $player = new Game(Language::English, 6, DrawnBy::Player, Mode::Solo, Turns::PlayerDraws);
        self::assertSame([DrawnBy::Player, DrawnBy::Player, DrawnBy::Player], array_map($player->drawnByInRound(...), [1, 2, 3]));

        $ai = new Game(Language::English, 6, DrawnBy::Ai, Mode::Duel, Turns::AiDraws);
        self::assertSame([DrawnBy::Ai, DrawnBy::Ai, DrawnBy::Ai], array_map($ai->drawnByInRound(...), [1, 2, 3]));
    }

    public function testAGameKeepsItsThemesAndTheLengthOfItsRounds(): void
    {
        $default = new Game(Language::English);
        self::assertSame(Theme::cases(), $default->getThemes());
        self::assertSame(60, $default->getRoundSeconds());
        self::assertSame(Turns::Alternate, $default->getTurns());

        $game = new Game(Language::English, 3, DrawnBy::Ai, Mode::Solo, Turns::AiDraws, 30, [Theme::Nature, Theme::Animals]);
        self::assertSame([Theme::Animals, Theme::Nature], $game->getThemes());
        self::assertSame(30, $game->getRoundSeconds());
    }

    public function testAGameNeedsATheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Game(Language::English, 6, DrawnBy::Ai, Mode::Solo, Turns::Alternate, 60, []);
    }
}
