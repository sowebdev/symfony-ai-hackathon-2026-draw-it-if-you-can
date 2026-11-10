<?php

namespace App\Entity;

use App\Drawing\GridStroke;
use App\Drawing\StrokePoints;
use App\Repository\StrokeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrokeRepository::class)]
#[ORM\UniqueConstraint(columns: ['round_id', 'position'])]
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

    /**
     * For a stroke of the AI: what it says the stroke depicts ("left ear").
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $part = null;

    /**
     * For a stroke of the AI: the grid cells as the model wrote them, before StrokeTracer.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $written = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Round $round,
        #[ORM\Column]
        private int $position,
        StrokePoints $points,
        ?GridStroke $source = null,
    ) {
        $this->points = $points->points;
        $this->part = $source?->part;
        $this->written = $source?->written;
    }

    public function getPart(): ?string
    {
        return $this->part;
    }

    public function getWritten(): ?string
    {
        return $this->written;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    /**
     * Order of the stroke in the round, from 1.
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
