<?php

namespace App\Ai;

/**
 * What the AI does in the game. Each role has its own model, chosen on the models screen.
 */
enum Role: string
{
    /** Draws, stroke by stroke, while the player guesses. */
    case Drawer = 'drawer';

    /** Picks the word the player draws, then guesses the drawing: it must see images. */
    case Guesser = 'guesser';
}
