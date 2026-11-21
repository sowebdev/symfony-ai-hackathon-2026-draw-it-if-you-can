<?php

namespace App\Tests\Smoke;

use App\Ai\DrawsStrokes;
use App\Drawing\GridStroke;
use App\Game\Theme;

/**
 * The drawing model of the smoke test: a cat in four strokes, one every half second, as a model streams
 * them; asked for details, whiskers.
 */
final readonly class ScriptedDrawer implements DrawsStrokes
{
    private const array CAT = [
        ['head', 'x25y15,x37y20,x40y32,x32y42,x18y42,x10y32,x13y20,x25y15'],
        ['left ear', 'x14y21,x13y9,x21y16'],
        ['right ear', 'x29y16,x37y9,x36y21'],
        ['eyes', 'x20y27,x21y28,x29y27,x30y28'],
    ];
    private const array WHISKERS = [['whiskers', 'x8y33,x20y34,x30y34,x42y33']];

    public function draw(string $word, Theme $theme, array $drawn = [], array $wrongAnswers = []): \Generator
    {
        foreach ([] === $drawn ? self::CAT : self::WHISKERS as [$part, $cells]) {
            usleep(500_000);
            if (null !== $stroke = GridStroke::fromWritten($part, $cells)) {
                yield $stroke;
            }
        }
    }
}
