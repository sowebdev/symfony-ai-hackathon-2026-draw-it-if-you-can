<?php

namespace App\Ai;

use App\Drawing\GridStroke;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The tool the model draws with: one call per stroke. It only answers the model; the game reads
 * the strokes from the stream as soon as they are written (Drawer), without waiting for the
 * tools to run at the end of the answer. One pen per drawing, hence not a service.
 */
#[Exclude]
#[AsTool('draw_stroke', 'Draw one stroke of the sketch: the pen goes through the given grid cells, in order, without lifting.')]
final class Pen
{
    private int $strokes = 0;

    public function __construct(private readonly int $maxStrokes)
    {
    }

    /**
     * @param string $part  What this stroke depicts, e.g. "left ear"
     * @param string $cells Comma-separated grid cells, e.g. "x10y12,x15y20,x18y25"
     */
    public function __invoke(string $part, string $cells): string
    {
        if ($this->strokes >= $this->maxStrokes) {
            return 'Stroke limit reached: the sketch is finished, do not call draw_stroke again.';
        }
        if (null === GridStroke::fromWritten($part, $cells)) {
            return 'No valid cell in this stroke: cells are written like x10y12, x and y from 1 to 50.';
        }
        ++$this->strokes;

        return \sprintf('Stroke %d of at most %d drawn.', $this->strokes, $this->maxStrokes);
    }
}
