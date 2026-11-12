<?php

namespace App\Entity;

use App\Repository\HintRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A word the copilot, an agent connected over MCP, whispers to the player who guesses the drawing
 * of the AI. Only the player can answer it.
 */
#[ORM\Entity(repositoryClass: HintRepository::class)]
class Hint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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
}
