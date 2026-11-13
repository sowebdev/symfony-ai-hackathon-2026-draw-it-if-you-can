<?php

namespace App\Tests\Unit\Game;

use App\Entity\Game;
use App\Entity\Round;
use App\Game\AgentTurn;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AgentTurnTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testInTheLobbyNothingComesUntilTheFirstRound(): void
    {
        $game = new Game(Language::English, 6, DrawnBy::Ai, Mode::Copilot);
        $turn = AgentTurn::from(null);

        self::assertNull($turn->news($game, null, 0));
        self::assertSame(AgentTurn::NEW_ROUND, $turn->news($game, new Round($game, 1, 'cat', 60_000, Theme::Animals), 0), 'The player started the game');
    }

    public function testACopilotWaitsForStrokesItHasNotSeen(): void
    {
        self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Ai, Mode::Copilot);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);
        $turn = AgentTurn::from($round);

        self::assertNull($turn->news($game, $round, 0), 'The canvas is empty');
        self::assertSame(AgentTurn::NEW_STROKES, $turn->news($game, $round, 3));

        $round->agentLooked(3);
        self::assertNull($turn->news($game, $round, 3), 'The agent saw these strokes');
        self::assertSame(AgentTurn::NEW_STROKES, $turn->news($game, $round, 4));
    }

    public function testAStrokeTheAgentWasToldOfIsNoNews(): void
    {
        self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Ai, Mode::Duel);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);
        $round->agentTold(2);

        self::assertNull(AgentTurn::from($round)->news($game, $round, 2), 'A status gave these strokes');
        self::assertSame(AgentTurn::NEW_STROKES, AgentTurn::from($round)->news($game, $round, 3));
    }

    public function testTheCopilotOfAPlayerWhoDrawsWaitsForTheGuessesOfTheAiNotForStrokes(): void
    {
        self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Player, Mode::Copilot);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);

        self::assertNull(AgentTurn::from($round)->news($game, $round, 5), 'The player draws on');
        self::assertSame(AgentTurn::NEW_AI_GUESSES, AgentTurn::from($round)->news($game, $round, 5, 1));

        $round->agentToldAiGuesses(1);
        self::assertNull(AgentTurn::from($round)->news($game, $round, 6, 1), 'A status gave this guess');
        self::assertSame(AgentTurn::NEW_AI_GUESSES, AgentTurn::from($round)->news($game, $round, 6, 2));
    }

    public function testARivalIsNotWokenByTheGuessesOfTheAi(): void
    {
        self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Player, Mode::Duel);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);
        $round->agentTold(5);

        self::assertNull(AgentTurn::from($round)->news($game, $round, 5, 2));
    }

    public function testARivalPlaysOnEveryDrawing(): void
    {
        self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Player, Mode::Duel);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);

        self::assertSame(AgentTurn::NEW_STROKES, AgentTurn::from($round)->news($game, $round, 5));
    }

    public function testTheEndOfARoundComesOnceThenTheNextRound(): void
    {
        $clock = self::mockTime();
        $game = new Game(Language::English, 6, DrawnBy::Player, Mode::Copilot);
        $round = new Round($game, 1, 'cat', 60_000, Theme::Animals);
        $turn = AgentTurn::from($round);

        $clock->sleep(62);
        self::assertSame(AgentTurn::ROUND_OVER, $turn->news($game, $round, 0), 'The time is up');

        $over = AgentTurn::from($round);
        self::assertNull($over->news($game, $round, 0), 'The agent knows the round is over');
        self::assertSame(AgentTurn::NEW_ROUND, $over->news($game, new Round($game, 2, 'dog', 60_000, Theme::Animals), 0));
    }
}
