<?php

namespace App\Game;

/**
 * Frames what is drawn: the AI picks the word in the theme, and the guesser knows it.
 */
enum Theme: string
{
    case Animals = 'animals';
    case Food = 'food';
    case EverydayObjects = 'everyday-objects';
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::Animals => 'Animals',
            self::Food => 'Food and cooking',
            self::EverydayObjects => 'Everyday objects',
            self::Developer => 'Developer life',
        };
    }

    /**
     * How the theme is described to the model.
     */
    public function description(): string
    {
        return match ($this) {
            self::Animals => 'animals',
            self::Food => 'food, dishes and kitchen utensils',
            self::EverydayObjects => 'everyday objects found at home or in the street',
            self::Developer => 'the daily life of a software developer: objects, tools and things around a computer',
        };
    }
}
