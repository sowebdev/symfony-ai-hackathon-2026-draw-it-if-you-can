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
    case EverydayObjects = 'everyday-objects';
    case Developer = 'developer';
    case Places = 'places';
    case Characters = 'characters';
    case Nature = 'nature';
    case Transport = 'transport';

    /**
     * The values of themes, as an entity stores them.
     *
     * @param list<self> $themes
     *
     * @return list<string>
     */
    public static function toValues(array $themes): array
    {
        return array_map(static fn (self $theme): string => $theme->value, $themes);
    }

    /**
     * The themes of stored values, in the order of the cases; an unknown value is left out.
     *
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        return array_values(array_filter(self::cases(), static fn (self $theme): bool => \in_array($theme->value, $values, true)));
    }

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
            self::EverydayObjects => 'everyday objects found at home or in the street',
            self::Developer => 'the daily life of a software developer: objects, tools and things around a computer',
            self::Places => 'places and buildings with a shape of their own, in town or in the wild',
            self::Characters => 'characters known by their job, their costume or their story, real or imaginary',
            self::Nature => 'nature: plants, landscapes and what the sky and the weather show',
            self::Transport => 'vehicles and means of transport, on land, on water, in the air or in space',
        };
    }
}
