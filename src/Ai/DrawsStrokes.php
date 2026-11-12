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
     * A first sketch, or, when strokes are already drawn, details that make it clearer.
     *
     * @param list<GridStroke> $drawn        Strokes already on the canvas, in order
     * @param list<string>     $wrongAnswers What the player answered so far, all wrong
     *
     * @return iterable<GridStroke> Each new stroke as soon as it is written
     */
    public function draw(string $word, Theme $theme, array $drawn = [], array $wrongAnswers = []): iterable;
}
