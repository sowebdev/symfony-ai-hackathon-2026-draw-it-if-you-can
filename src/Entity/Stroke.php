<?php

namespace App\Entity;

use App\Drawing\StrokePoints;
use App\Repository\StrokeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrokeRepository::class)]
#[ORM\UniqueConstraint(columns: ['game_id', 'position'])]
class Stroke
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var list<array{int, int, int}>
     */
    #[ORM\Column]
    private array $points;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Game $game,
        #[ORM\Column]
        private int $position,
        StrokePoints $points,
    ) {
        $this->points = $points->points;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    /**
     * Order of the stroke in the game, from 1.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function getPoints(): array
    {
        return $this->points;
    }
}
