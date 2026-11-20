<?php

namespace App\Game;

use App\Ai\Guesser;
use App\Ai\Judge;
use App\Drawing\StrokeRenderer;
use App\Entity\Guess;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Repository\GuessRepository;
use App\Repository\RoundRepository;
use App\Repository\StrokeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * While the player draws, the AI calls out a word every few seconds. The game sets the rhythm,
 * the model only answers one word at a time. Runs inside one server-sent events response.
 */
final readonly class GuessLoop
{
    /**
     * Guesses on a drawing that did not change, before waiting for a new stroke.
     */
    private const int GUESSES_PER_DRAWING = 2;

    /**
     * Pause while waiting for a new stroke.
     */
    private const int IDLE_PAUSE_MS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepository $rounds,
        private StrokeRepository $strokes,
        private GuessRepository $guesses,
        private StrokeRenderer $renderer,
        private Guesser $guesser,
        private Judge $judge,
        private LoggerInterface $logger,
        #[Autowire('%game.guess_pause_ms%')]
        private int $pauseMs,
        #[Autowire('%game.guess_stream_max_s%')]
        private int $maxDurationS,
    ) {
    }

    /**
     * @return \Generator<ServerEvent>
     */
    public function run(Round $round): \Generator
    {
        // Bounded by the deadline below, not by PHP's 30 s: the stream lasts longer.
        set_time_limit(0);
        $roundId = $round->getId();
        // The stream ends with the round, or before: the browser reconnects by itself.
        $deadline = microtime(true) + min($this->maxDurationS, ($round->remainingMs() + Round::GRACE_MS) / 1000);
        $lastDrawing = null;
        $guessesOnDrawing = 0;

        while (microtime(true) < $deadline) {
            // Strokes come from other requests: read them fresh at each turn.
            $this->entityManager->clear();
            $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
            if ($round->isOver()) {
                yield $this->overEvent($round);

                return;
            }

            $strokes = $this->strokes->findByRound($round);
            $drawing = self::fingerprint($strokes);
            if ($drawing !== $lastDrawing) {
                $lastDrawing = $drawing;
                $guessesOnDrawing = 0;
            }
            if ([] === $strokes || $guessesOnDrawing >= self::GUESSES_PER_DRAWING) {
                // A comment keeps the connection alive, and tells whether the player left.
                yield new ServerEvent('', comment: 'waiting');
                $this->pause(self::IDLE_PAUSE_MS);
                continue;
            }

            $guess = $this->guessOnce($round, $strokes);
            ++$guessesOnDrawing;
            if (null !== $guess) {
                yield new ServerEvent((string) json_encode(['id' => $guess->getId(), 'word' => $guess->getWord(), 'verdict' => $guess->getVerdict()->value]), type: 'guess');
                if ($round->isFound()) {
                    yield $this->overEvent($round);

                    return;
                }
            }
            $this->pause($this->pauseMs);
        }
    }

    /**
     * @param list<Stroke> $strokes
     */
    private function guessOnce(Round $round, array $strokes): ?Guess
    {
        $game = $round->getGame();
        $said = array_map(static fn (Guess $guess): string => $guess->getWord(), $this->guesses->findByRound($round));
        $png = $this->renderer->toPng(array_map(static fn (Stroke $stroke): array => $stroke->getPoints(), $strokes));

        try {
            $word = $this->guesser->guess($png, $round->getTheme(), $game->getLanguage(), $said);
        } catch (\Throwable $exception) {
            // A model that stops answering (too slow, gone) is a guess that does not come, not the end of the stream.
            $this->logger->warning('The AI could not guess: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return null;
        }
        // A slow model may answer after the end of the round: too late.
        if (null === $word || $round->isTimeUp() || \in_array(WordMatcher::normalize($word), array_map(WordMatcher::normalize(...), $said), true)) {
            return null;
        }

        // The word is said now, whatever the time the judge takes.
        $saidAfterMs = $round->elapsedMs();
        $verdict = $this->judge->judge($word, $round->getTargetWord(), $game->getLanguage(), $game->isEasyMode());
        $guess = new Guess($round, $word, $saidAfterMs, Author::Ai, $verdict);
        $this->entityManager->persist($guess);
        if ($verdict->isAccepted()) {
            $round->markFound($guess);
        }
        $this->entityManager->flush();

        return $guess;
    }

    /**
     * "found" when the AI found the word, "over" otherwise: out of time, or found by the rival of a duel.
     */
    private function overEvent(Round $round): ServerEvent
    {
        return $round->isFound() && Author::Ai === $round->getFoundBy()
            ? new ServerEvent((string) json_encode(['foundAfterMs' => $round->getFoundAfterMs()]), type: 'found')
            : new ServerEvent('{}', type: 'over');
    }

    /**
     * @param list<Stroke> $strokes
     */
    private static function fingerprint(array $strokes): string
    {
        return implode(',', array_map(static fn (Stroke $stroke): string => $stroke->getPosition().':'.\count($stroke->getPoints()), $strokes));
    }

    private function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
