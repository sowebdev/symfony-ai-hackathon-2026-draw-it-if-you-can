<?php

namespace App\Game;

/**
 * Who draws in a round; the other one guesses.
 */
enum DrawnBy: string
{
    case Player = 'player';
    case Ai = 'ai';
}
