<?php

namespace App\Ai;

use App\Drawing\GridStroke;
use App\Game\Theme;

/**
 * Draws a word, stroke by stroke. A test replaces the model with a script.
 */
interface DrawsStrokes
{
    /**
     * @return iterable<GridStroke> Each stroke as soon as it is written
     */
    public function draw(string $word, Theme $theme): iterable;
}
