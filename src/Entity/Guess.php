<?php

namespace App\Entity;

use App\Repository\GuessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A word the AI called out while the player was drawing.
 */
#[ORM\Entity(repositoryClass: GuessRepository::class)]
class Guess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $accepted = false;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Round $round,
        #[ORM\Column(length: 100)]
        private string $word,
        #[ORM\Column]
        private int $saidAfterMs,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): Round
    {
        return $this->round;
    }

    public function getWord(): string
    {
        return $this->word;
    }

    public function getSaidAfterMs(): int
    {
        return $this->saidAfterMs;
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function accept(): void
    {
        $this->accepted = true;
    }
}
