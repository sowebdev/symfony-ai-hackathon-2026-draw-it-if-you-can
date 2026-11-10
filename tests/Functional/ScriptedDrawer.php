<?php

namespace App\Tests\Functional;

use App\Ai\DrawsStrokes;
use App\Drawing\GridStroke;
use App\Game\Theme;

/**
 * Replaces the drawing model: the strokes are given by the test, as the model would write them,
 * and the model may fail after them.
 */
final class ScriptedDrawer implements DrawsStrokes
{
    /** @var list<array{string, string}> */
    public array $drawn = [];

    /**
     * @param list<array{string, string}> $strokes [part, cells] pairs
     */
    public function __construct(private readonly array $strokes, private readonly ?\Throwable $failure = null)
    {
    }

    public function draw(string $word, Theme $theme): \Generator
    {
        $this->drawn[] = [$word, $theme->value];
        foreach ($this->strokes as [$part, $cells]) {
            if (null !== $stroke = GridStroke::fromWritten($part, $cells)) {
                yield $stroke;
            }
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
