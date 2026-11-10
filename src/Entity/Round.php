<?php

namespace App\Entity;

use App\Game\DrawnBy;
use App\Repository\RoundRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Uid\Uuid;

/**
 * One drawing to guess: the player draws and the AI guesses, or the other way round.
 */
#[ORM\Entity(repositoryClass: RoundRepository::class)]
class Round
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Unix time in milliseconds: a DATETIME column would lose them.
     */
    #[ORM\Column(type: 'bigint')]
    private int $startedAtMs;

    /**
     * Milliseconds from the start of the round to the right guess, null until found.
     */
    #[ORM\Column(nullable: true)]
    private ?int $foundAfterMs = null;

    /**
     * Whether a request has started the drawing of the AI: only one may run it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $aiDrawingStarted = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $aiDrawingFinished = false;

    /**
     * The player gave up guessing the drawing of the AI.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $givenUp = false;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Game $game,
        #[ORM\Column(length: 100)]
        private string $targetWord,
        #[ORM\Column(length: 10, enumType: DrawnBy::class, options: ['default' => 'player'])]
        private DrawnBy $drawnBy = DrawnBy::Player,
    ) {
        $this->id = Uuid::v7();
        $this->startedAtMs = self::toMs(new DatePoint());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getTargetWord(): string
    {
        return $this->targetWord;
    }

    public function getStartedAtMs(): int
    {
        return $this->startedAtMs;
    }

    /**
     * Milliseconds since the round started: the clock of the strokes and of the guesses.
     */
    public function elapsedMs(?\DateTimeImmutable $now = null): int
    {
        return self::toMs($now ?? new DatePoint()) - $this->startedAtMs;
    }

    private static function toMs(\DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }

    public function isFound(): bool
    {
        return null !== $this->foundAfterMs;
    }

    public function getFoundAfterMs(): ?int
    {
        return $this->foundAfterMs;
    }

    public function markFound(Guess $guess): void
    {
        if ($this->isFound()) {
            return;
        }
        $guess->accept();
        $this->foundAfterMs = $guess->getSaidAfterMs();
    }

    public function getDrawnBy(): DrawnBy
    {
        return $this->drawnBy;
    }

    public function isDrawnByAi(): bool
    {
        return DrawnBy::Ai === $this->drawnBy;
    }

    /**
     * Found or given up: nothing more to draw nor to guess.
     */
    public function isOver(): bool
    {
        return $this->isFound() || $this->givenUp;
    }

    public function isGivenUp(): bool
    {
        return $this->givenUp;
    }

    public function giveUp(): void
    {
        if (!$this->isFound()) {
            $this->givenUp = true;
        }
    }

    public function isAiDrawingStarted(): bool
    {
        return $this->aiDrawingStarted;
    }

    public function isAiDrawingFinished(): bool
    {
        return $this->aiDrawingFinished;
    }

    public function finishAiDrawing(): void
    {
        $this->aiDrawingFinished = true;
    }
}
