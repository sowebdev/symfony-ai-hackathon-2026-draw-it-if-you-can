<?php

namespace App\Entity;

use App\Game\Author;
use App\Game\DrawnBy;
use App\Game\Theme;
use App\Repository\RoundRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Uid\Uuid;

/**
 * One drawing to guess: the player draws and the AI guesses, or the other way round, against
 * the clock. The round is over when the word is found, when the player gives up, or when the
 * time is up.
 */
#[ORM\Entity(repositoryClass: RoundRepository::class)]
#[ORM\UniqueConstraint(columns: ['game_id', 'number'])]
class Round
{
    /**
     * After the end, a word or a stroke still counts for this long: it was sent at the last
     * second, and the network took the rest.
     */
    public const int GRACE_MS = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Unix time in milliseconds: a DATETIME column would lose them.
     */
    #[ORM\Column(type: 'bigint')]
    private int $startedAtMs;

    /**
     * Milliseconds from the start of the round to the validation of the right answer, null until found.
     */
    #[ORM\Column(nullable: true)]
    private ?int $foundAfterMs = null;

    /**
     * Who said the right word, null until found: in a duel, it tells which side wins the points.
     */
    #[ORM\Column(length: 10, nullable: true, enumType: Author::class)]
    private ?Author $foundBy = null;

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

    #[ORM\Column(length: 10, enumType: DrawnBy::class, options: ['default' => 'player'])]
    private DrawnBy $drawnBy;

    /**
     * How many strokes the agent saw when it last looked at the drawing: it waits for more.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $strokesSeenByAgent = 0;

    /**
     * How many strokes the last status told the agent of: a wait answers only for more.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $strokesToldToAgent = 0;

    /**
     * How many guesses of the AI the last status told the copilot of, while the player draws: a
     * wait answers only for more.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $aiGuessesToldToAgent = 0;

    /**
     * @param int $number     Order of the round in the game, from 1: it tells who draws
     * @param int $durationMs Kept on the round, so that a change of configuration does not change it
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Game $game,
        #[ORM\Column(options: ['default' => 1])]
        private int $number,
        #[ORM\Column(length: 100)]
        private string $targetWord,
        #[ORM\Column(options: ['default' => 60000])]
        private int $durationMs,
        /** Dealt for each round: it frames the word, and the guesser knows it. */
        #[ORM\Column(enumType: Theme::class)]
        private Theme $theme,
        /** The indefinite article the model gave the word ("un", "an", "un'"), null when it gave none or for an older round. */
        #[ORM\Column(length: 10, nullable: true)]
        private ?string $targetArticle = null,
    ) {
        if ($number < 1 || $number > $game->getRoundCount()) {
            throw new \InvalidArgumentException(\sprintf('Round %d is not in a game of %d rounds.', $number, $game->getRoundCount()));
        }
        $this->id = Uuid::v7();
        $this->drawnBy = $game->drawnByInRound($number);
        $this->startedAtMs = self::toMs(new DatePoint());
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function isLast(): bool
    {
        return $this->number >= $this->game->getRoundCount();
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * Milliseconds left on the clock, 0 once the time is up.
     */
    public function remainingMs(?\DateTimeImmutable $now = null): int
    {
        return max(0, $this->durationMs - $this->elapsedMs($now));
    }

    /**
     * Milliseconds left on the clock when the round ended, or now while it is played: the clock
     * stops when the word is found. A round given up or out of time has none left.
     */
    public function timeLeftMs(?\DateTimeImmutable $now = null): int
    {
        return match (true) {
            $this->isFound() => max(0, $this->durationMs - (int) $this->foundAfterMs),
            $this->isOver($now) => 0,
            default => $this->remainingMs($now),
        };
    }

    /**
     * Milliseconds since the round ended, null while it is played. A round given up counts as
     * ended at the end of its time: when it was given up is not kept.
     */
    public function msSinceEnd(?\DateTimeImmutable $now = null): ?int
    {
        if (!$this->isOver($now)) {
            return null;
        }

        return max(0, $this->elapsedMs($now) - ($this->foundAfterMs ?? $this->durationMs));
    }

    /**
     * The time is up, grace included: nothing more counts.
     */
    public function isTimeUp(?\DateTimeImmutable $now = null): bool
    {
        return $this->elapsedMs($now) > $this->durationMs + self::GRACE_MS;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    /**
     * The word with its article, as shown to the player ("un château fort"). Only getTargetWord() counts for the answer.
     */
    public function getTargetPhrase(): string
    {
        if (null === $this->targetArticle) {
            return $this->targetWord;
        }

        return $this->targetArticle.(str_ends_with($this->targetArticle, "'") ? '' : ' ').$this->targetWord;
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

    /**
     * The word is found when the code recognises it, at the moment it was said: the points count
     * from then. A word said after the time, grace included, finds nothing.
     */
    public function markFound(Guess $guess): void
    {
        $saidAfterMs = $guess->getSaidAfterMs();
        if ($this->isFound() || $this->givenUp || $saidAfterMs > $this->durationMs + self::GRACE_MS) {
            return;
        }
        $guess->accept();
        $this->foundAfterMs = $saidAfterMs;
        $this->foundBy = $guess->getAuthor();
    }

    public function getFoundBy(): ?Author
    {
        return $this->foundBy;
    }

    /**
     * Found by the rival agent of a duel: the points go to the other side.
     */
    public function isFoundByRival(): bool
    {
        return true === $this->foundBy?->isRival();
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
     * Found, given up or out of time: nothing more to draw nor to guess.
     */
    public function isOver(?\DateTimeImmutable $now = null): bool
    {
        return $this->isFound() || $this->givenUp || $this->isTimeUp($now);
    }

    public function isGivenUp(): bool
    {
        return $this->givenUp;
    }

    public function giveUp(): void
    {
        if (!$this->isOver()) {
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

    public function getStrokesSeenByAgent(): int
    {
        return $this->strokesSeenByAgent;
    }

    /**
     * The agent looked at the drawing, which had this many strokes.
     */
    public function agentLooked(int $strokes): void
    {
        $this->strokesSeenByAgent = max($this->strokesSeenByAgent, $strokes);
    }

    /**
     * A status told the agent the round had this many strokes.
     */
    public function agentTold(int $strokes): void
    {
        $this->strokesToldToAgent = max($this->strokesToldToAgent, $strokes);
    }

    /**
     * Strokes the agent knows of, by looking at the drawing or by a status.
     */
    public function getStrokesKnownByAgent(): int
    {
        return max($this->strokesSeenByAgent, $this->strokesToldToAgent);
    }

    /**
     * A status told the copilot the AI had made this many guesses.
     */
    public function agentToldAiGuesses(int $guesses): void
    {
        $this->aiGuessesToldToAgent = max($this->aiGuessesToldToAgent, $guesses);
    }

    public function getAiGuessesToldToAgent(): int
    {
        return $this->aiGuessesToldToAgent;
    }
}
