<?php

namespace App\Entity;

use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Theme;
use App\Repository\GameRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A game: rounds where the player and the AI take turns to draw, for a score they share.
 */
#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(enumType: Theme::class)]
        private Theme $theme,
        #[ORM\Column(enumType: Language::class)]
        private Language $language,
        #[ORM\Column(options: ['default' => 1])]
        private int $roundCount = 6,
        #[ORM\Column(length: 10, enumType: DrawnBy::class, options: ['default' => 'player'])]
        private DrawnBy $firstDrawnBy = DrawnBy::Ai,
    ) {
        if ($roundCount < 1) {
            throw new \InvalidArgumentException('A game has at least one round.');
        }
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getRoundCount(): int
    {
        return $this->roundCount;
    }

    public function getFirstDrawnBy(): DrawnBy
    {
        return $this->firstDrawnBy;
    }

    /**
     * Who draws round $number, from 1: the one who starts draws the odd rounds.
     */
    public function drawnByInRound(int $number): DrawnBy
    {
        return 1 === $number % 2 ? $this->firstDrawnBy : $this->firstDrawnBy->other();
    }
}
