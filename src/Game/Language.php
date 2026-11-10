<?php

namespace App\Game;

/**
 * The language the player chose: the word to draw and the guesses are in it.
 */
enum Language: string
{
    case English = 'en';
    case French = 'fr';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
        };
    }

    /**
     * How the language is named to the model.
     */
    public function nameInEnglish(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'French',
        };
    }
}
