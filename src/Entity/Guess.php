<?php

namespace App\Entity;

use App\Game\Author;
use App\Repository\GuessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A word said to guess a drawing: by the AI while the player draws, by the player while the AI
 * draws, and in a duel by the rival agent in both.
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
        #[ORM\Column(length: 10, enumType: Author::class, options: ['default' => 'player'])]
        private Author $author,
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

    public function getAuthor(): Author
    {
        return $this->author;
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
