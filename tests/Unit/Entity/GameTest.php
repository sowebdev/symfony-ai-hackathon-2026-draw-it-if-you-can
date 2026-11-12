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
    public function testAnAgentMayHaveNoName(): void
    {
        $game = new Game(Theme::Animals, Language::English, 6, DrawnBy::Ai, Mode::Copilot);
        $game->agentSeen(' ', new \DateTimeImmutable());

        self::assertNull($game->getAgentName());
        self::assertNull($game->getAgentDisplayName());
        self::assertTrue($game->isAgentConnected());
        self::assertTrue($game->isWithAgent());
        self::assertFalse($game->isDuel());
    }
}
