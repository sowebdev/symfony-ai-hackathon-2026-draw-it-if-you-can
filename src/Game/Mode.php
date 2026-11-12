<?php

namespace App\Game;

/**
 * How the player plays: alone against the AI, helped by a copilot, or in a duel against an agent.
 * The copilot and the rival are agents connected over MCP.
 */
enum Mode: string
{
    case Solo = 'solo';
    case Copilot = 'copilot';
    case Duel = 'duel';

    /**
     * An agent plays along over MCP: the game waits for it in a lobby.
     */
    public function withAgent(): bool
    {
        return self::Solo !== $this;
    }
}
