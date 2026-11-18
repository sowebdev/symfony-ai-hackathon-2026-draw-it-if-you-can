<?php

namespace App\Game;

/**
 * The language the player chose: the word to draw and the guesses are in it.
 */
enum Language: string
{
    case English = 'en';
    case French = 'fr';
    case German = 'de';
    case Spanish = 'es';
    case Italian = 'it';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
            self::German => 'Deutsch',
            self::Spanish => 'Español',
            self::Italian => 'Italiano',
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
            self::German => 'German',
            self::Spanish => 'Spanish',
            self::Italian => 'Italian',
        };
    }
}
