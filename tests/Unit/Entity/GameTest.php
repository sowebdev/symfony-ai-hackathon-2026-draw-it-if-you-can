<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testTheFirstAgentToCallTakesTheGame(): void
    {
        $game = new Game(Theme::Animals, Language::English, 6, DrawnBy::Ai, Mode::Duel);
        $now = new \DateTimeImmutable('2026-11-12 15:00:00');
        self::assertTrue($game->mayBePlayedBy('a', false, $now, 30));

        $game->agentSeen('claude-code', 'a', $now);
        self::assertTrue($game->mayBePlayedBy('a', false, $now, 30));
        self::assertFalse($game->mayBePlayedBy('b', false, $now->modify('+30 seconds'), 30), 'Another agent, during the game');
        self::assertTrue($game->mayBePlayedBy('b', true, $now, 30), 'Another agent, in the lobby');
        self::assertTrue($game->mayBePlayedBy('b', false, $now->modify('+31 seconds'), 30), 'The agent of the game went silent');
    }

    public function testAnAgentWithoutANameIsAnMcpClient(): void
    {
        $game = new Game(Theme::Animals, Language::English, 6, DrawnBy::Ai, Mode::Copilot);
        $game->agentSeen(' ', null, new \DateTimeImmutable());

        self::assertSame('MCP client', $game->getAgentName());
        self::assertTrue($game->isAgentConnected());
        self::assertTrue($game->isWithAgent());
        self::assertFalse($game->isDuel());
    }
}
