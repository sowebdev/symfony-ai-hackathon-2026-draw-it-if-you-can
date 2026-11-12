<?php

namespace App\Game;

/**
 * Who said a word to guess a drawing: the player, the AI of the game, or the rival agent of a duel.
 */
enum Author: string
{
    case Player = 'player';
    case Ai = 'ai';
    case Agent = 'agent';

    /**
     * The side that wins the points of a word: the player and the AI play together, the rival against them.
     */
    public function isRival(): bool
    {
        return self::Agent === $this;
    }
}
