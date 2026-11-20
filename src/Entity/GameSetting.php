<?php

namespace App\Entity;

use App\Game\Settings;
use App\Game\Theme;
use App\Game\Turns;
use App\Repository\GameSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The settings of the next games: a single row, saved from the settings screen. Without a row, the
 * game uses its defaults.
 */
#[ORM\Entity(repositoryClass: GameSettingRepository::class)]
class GameSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> The values of the active themes */
    #[ORM\Column(type: 'json')]
    private array $themes;

    #[ORM\Column(length: 10, enumType: Turns::class)]
    private Turns $turns;

    #[ORM\Column]
    private int $rounds;

    #[ORM\Column]
    private int $roundSeconds;

    #[ORM\Column(options: ['default' => false])]
    private bool $easyMode = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Settings $settings)
    {
        $this->apply($settings);
    }

    public function apply(Settings $settings): void
    {
        $this->themes = array_map(static fn (Theme $theme): string => $theme->value, $settings->themes);
        $this->turns = $settings->turns;
        $this->rounds = $settings->rounds;
        $this->roundSeconds = $settings->roundSeconds;
        $this->easyMode = $settings->easyMode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function settings(): Settings
    {
        $themes = array_values(array_filter(Theme::cases(), fn (Theme $theme): bool => \in_array($theme->value, $this->themes, true)));

        return new Settings($themes, $this->turns, $this->rounds, $this->roundSeconds, $this->easyMode);
    }
}
