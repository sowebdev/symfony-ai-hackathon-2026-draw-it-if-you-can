<?php

namespace App\Entity;

use App\Repository\TipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece of advice the copilot, an agent connected over MCP, gives to the player who draws: what
 * to add or change for the AI to find the word. The player decides what to do with it.
 */
#[ORM\Entity(repositoryClass: TipRepository::class)]
class Tip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Round $round,
        #[ORM\Column(length: 120)]
        private string $text,
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

    public function getText(): string
    {
        return $this->text;
    }

    public function getSaidAfterMs(): int
    {
        return $this->saidAfterMs;
    }
}
