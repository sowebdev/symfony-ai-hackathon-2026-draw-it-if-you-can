<?php

namespace App\Ai;

/**
 * What the AI does in the game. Each role has its own endpoint and model (AI_<ROLE>_* in .env).
 */
enum Role: string
{
    /** Draws, stroke by stroke, while the player guesses. */
    case Drawer = 'drawer';

    /** Picks the word the player draws, then guesses the drawing: it must see images. */
    case Guesser = 'guesser';
}
