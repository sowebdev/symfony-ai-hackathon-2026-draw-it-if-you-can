<?php

namespace App\Game;

/**
 * Who draws in a game, a setting: the player and the AI take turns, or one side draws every round
 * and the other guesses. With an agent, the meaning is the same: the player's side, or the AI.
 */
enum Turns: string
{
    case Alternate = 'alternate';
    case PlayerDraws = 'player';
    case AiDraws = 'ai';

    /**
     * Who draws round $number, from 1, when $first draws the first round.
     */
    public function drawnByInRound(int $number, DrawnBy $first): DrawnBy
    {
        return match ($this) {
            self::Alternate => 1 === $number % 2 ? $first : $first->other(),
            self::PlayerDraws => DrawnBy::Player,
            self::AiDraws => DrawnBy::Ai,
        };
    }
}
