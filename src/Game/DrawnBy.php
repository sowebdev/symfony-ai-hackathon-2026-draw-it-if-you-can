<?php

namespace App\Game;

/**
 * Who draws in a round; the other one guesses.
 */
enum DrawnBy: string
{
    case Player = 'player';
    case Ai = 'ai';

    /**
     * Who draws the next round: the player and the AI take turns.
     */
    public function other(): self
    {
        return self::Player === $this ? self::Ai : self::Player;
    }
}
