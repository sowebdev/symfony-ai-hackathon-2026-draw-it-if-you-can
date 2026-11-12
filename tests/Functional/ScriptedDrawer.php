<?php

namespace App\Tests\Functional;

use App\Ai\DrawsStrokes;
use App\Drawing\GridStroke;
use App\Game\Theme;

/**
 * Replaces the drawing model: the strokes are given by the test, as the model would write them,
 * and the model may fail after them. Asked for details, it draws the detail strokes, if any.
 */
final class ScriptedDrawer implements DrawsStrokes
{
    /** @var list<array{string, string}> The first sketches asked: word and theme */
    public array $drawn = [];

    /** @var list<array{int, list<string>}> The details asked: strokes already drawn, and wrong answers */
    public array $detailed = [];

    /**
     * @param list<array{string, string}> $strokes [part, cells] pairs
     * @param list<array{string, string}> $details [part, cells] pairs drawn at each call for details
     */
    public function __construct(private readonly array $strokes, private readonly ?\Throwable $failure = null, private readonly array $details = [])
    {
    }

    public function draw(string $word, Theme $theme, array $drawn = [], array $wrongAnswers = []): \Generator
    {
        if ([] !== $drawn) {
            $this->detailed[] = [\count($drawn), $wrongAnswers];
            foreach ($this->details as [$part, $cells]) {
                if (null !== $stroke = GridStroke::fromWritten($part, $cells)) {
                    yield $stroke;
                }
            }

            return;
        }

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
