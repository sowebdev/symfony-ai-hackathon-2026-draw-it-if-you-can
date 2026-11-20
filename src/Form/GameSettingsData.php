<?php

namespace App\Form;

use App\Game\Settings;
use App\Game\Theme;
use App\Game\Turns;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the settings screen edits, before it becomes Settings.
 */
final class GameSettingsData
{
    public function __construct(
        /** @var list<Theme> */
        #[Assert\Count(min: 1, minMessage: 'error.no_theme')]
        public array $themes,
        public Turns $turns,
        #[Assert\Range(min: Settings::MIN_ROUNDS, max: Settings::MAX_ROUNDS)]
        public int $rounds,
        public int $roundSeconds,
    ) {
    }

    public static function from(Settings $settings): self
    {
        return new self($settings->themes, $settings->turns, $settings->rounds, $settings->roundSeconds);
    }

    public function toSettings(): Settings
    {
        return new Settings($this->themes, $this->turns, $this->rounds, $this->roundSeconds);
    }
}
