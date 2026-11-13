<?php

namespace App\Game;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Frames what is drawn: the AI picks the word in the theme, and the guesser knows it.
 */
enum Theme: string implements TranslatableInterface
{
    case Animals = 'animals';
    case Food = 'food';
    case EverydayObjects = 'everyday-objects';
    case Developer = 'developer';

    /**
     * The name shown to the player, in translations/messages.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('theme.'.$this->value, locale: $locale);
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
