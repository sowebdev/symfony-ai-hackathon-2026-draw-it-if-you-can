<?php

namespace App\Entity;

use App\Game\Author;
use App\Game\Verdict;
use App\Repository\GuessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A word said to guess a drawing: by the AI while the player draws, by the player while the AI
 * draws, and in a duel by the rival agent in both. It is kept as soon as it is said, pending, and
 * gets its verdict once judged (App\Game\Referee).
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
        /** What the judge said of the word, warm being neither found nor wrong; null while it is being judged. */
        #[ORM\Column(length: 10, nullable: true, enumType: Verdict::class)]
        private ?Verdict $verdict = null,
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

    /**
     * Null while the word is being judged.
     */
    public function getVerdict(): ?Verdict
    {
        return $this->verdict;
    }

    public function isPending(): bool
    {
        return null === $this->verdict;
    }

    /**
     * The verdict, once: a word refused for a judge that took too long keeps that verdict when the judge answers late.
     */
    public function judged(Verdict $verdict): void
    {
        $this->verdict ??= $verdict;
    }

    public function isWarm(): bool
    {
        return true === $this->verdict?->isWarm();
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
