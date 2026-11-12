<?php

namespace App\Controller;

use App\Game\DrawnBy;
use App\Game\Theme;

/**
 * Payload of the home form: the theme, and who draws the first round (the AI, so that the
 * player discovers the game by guessing).
 */
final readonly class NewGame
{
    public function __construct(
        public Theme $theme,
        public DrawnBy $drawnBy = DrawnBy::Ai,
    ) {
    }
}
