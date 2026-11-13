<?php

namespace App\Game;

use App\Ai\DrawsStrokes;
use App\Drawing\GridStroke;
use App\Drawing\StrokeTracer;
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
use Symfony\Component\Uid\Uuid;

/**
 * The AI draws while the player guesses. Runs inside one server-sent events response: the first
 * request draws and stores each stroke; a request that comes later (a reload) replays the stored
 * strokes, then follows the new ones until the drawing is finished.
 *
 * The AI draws until the end of the round: after its first sketch, as long as the word is not
 * found and there is time left, it is asked for details, knowing its strokes and the wrong
 * answers of the player, like a player who completes a drawing.
 */
final readonly class DrawingLoop
{
    /** Pause of the pen between two strokes, on top of the time it takes to draw one. */
    private const int PEN_LIFT_MS = 300;

    /** Pause while following a drawing run by another request. */
    private const int FOLLOW_PAUSE_MS = 300;

    /** A drawing without a new stroke for this long has died with its request: it is over. */
    private const int STALE_S = 60;

    /** Technical guards, not rules of the game: calls to the model, and strokes on the canvas. */
    private const int MAX_PASSES = 8;
    private const int MAX_STROKES = 80;

    /** The model added nothing this many times in a row: it has nothing more to draw. */
    private const int MAX_EMPTY_PASSES = 2;

    /** Pause between two passes: the player looks at the drawing, the pen thinks. */
    private const int PASS_PAUSE_MS = 1500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepository $rounds,
        private StrokeRepository $strokes,
        private GuessRepository $guesses,
        private DrawsStrokes $drawer,
        private StrokeTracer $tracer,
        private LoggerInterface $logger,
        #[Autowire('%game.drawing_stream_max_s%')]
        private int $maxDurationS,
        #[Autowire('%game.pen_rhythm%')]
        private bool $penRhythm,
    ) {
    }

    /**
     * @return \Generator<ServerEvent>
     */
    public function run(Round $round): \Generator
    {
        $sent = 0;
        foreach ($this->strokes->findByRound($round) as $stroke) {
            yield self::strokeEvent($stroke, false);
            $sent = $stroke->getPosition();
        }

        if (!$round->isOver() && $this->rounds->claimAiDrawing($round)) {
            yield from $this->draw($round);
        } else {
            yield from $this->follow($round, $sent);
        }
    }

    /**
     * @return \Generator<ServerEvent>
     */
    private function draw(Round $round): \Generator
    {
        // The drawing goes on if the player reloads the page: the next request follows it.
        ignore_user_abort(true);
        // Bounded by the end of the round and the guards, not by PHP's 30 s, which a slow model exceeds.
        set_time_limit(0);
        $roundId = $round->getId();
        $seed = crc32($roundId->toRfc4122());
        $nextAt = 0.0;
        $position = 0;
        $emptyPasses = 0;

        for ($pass = 1; $pass <= self::MAX_PASSES && $position < self::MAX_STROKES && $emptyPasses < self::MAX_EMPTY_PASSES; ++$pass) {
            $round = $this->fresh($roundId);
            if ($round->isOver()) {
                break;
            }
            if ($pass > 1) {
                $this->waitUntil(max($nextAt, microtime(true) + self::PASS_PAUSE_MS / 1000));
            }

            $before = $position;
            try {
                foreach ($this->drawer->draw($round->getTargetWord(), $round->getTheme(), ...$this->sketchSoFar($round)) as $written) {
                    // The player may have found the word, or given up, in another request; or the time is up.
                    $round = $this->fresh($roundId);
                    if ($round->isOver() || $position >= self::MAX_STROKES) {
                        break 2;
                    }

                    // A faster model waits: one stroke at a time, at the rhythm of a hand.
                    $this->waitUntil($nextAt);
                    $points = $this->tracer->draw($written, $round->elapsedMs(), $seed + ++$position);
                    $stroke = new Stroke($round, $position, $points, $written);
                    $this->entityManager->persist($stroke);
                    $this->entityManager->flush();
                    yield self::strokeEvent($stroke, true);
                    $nextAt = microtime(true) + (StrokeTracer::durationMs($points) + self::PEN_LIFT_MS) / 1000;
                }
            } catch (\Throwable $exception) {
                // The strokes already drawn stay: a model cut short (a loop stopped by the token cap) has
                // still drawn something to guess. Only a drawing without any stroke has failed.
                $this->logger->log(0 === $position ? 'error' : 'warning', 'The AI could not draw: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
                if (0 === $position) {
                    yield new ServerEvent('{}', type: 'failed');
                }
                break;
            }
            $emptyPasses = $position === $before ? $emptyPasses + 1 : 0;
        }

        $round = $this->fresh($roundId);
        $round->finishAiDrawing();
        $this->entityManager->flush();
        yield new ServerEvent((string) json_encode(['strokes' => $position]), type: 'finished');
    }

    /**
     * What the model is told when it adds details: its strokes, and the wrong answers of the player.
     *
     * @return array{list<GridStroke>, list<string>}
     */
    private function sketchSoFar(Round $round): array
    {
        $drawn = [];
        foreach ($this->strokes->findByRound($round) as $stroke) {
            $written = $stroke->getWritten();
            if (null !== $written && null !== $grid = GridStroke::fromWritten($stroke->getPart() ?? '', $written)) {
                $drawn[] = $grid;
            }
        }
        $answers = array_map(static fn (Guess $guess): string => $guess->getWord(), $this->guesses->findByRound($round));

        return [$drawn, $answers];
    }

    /**
     * The round as stored now: answers and giving up come from other requests.
     */
    private function fresh(Uuid $roundId): Round
    {
        $this->entityManager->clear();

        return $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
    }

    /**
     * @return \Generator<ServerEvent>
     */
    private function follow(Round $round, int $sent): \Generator
    {
        set_time_limit(0);
        $roundId = $round->getId();
        $deadline = microtime(true) + $this->maxDurationS;
        $lastProgress = microtime(true);
        while (microtime(true) < $deadline) {
            $this->entityManager->clear();
            $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
            foreach ($this->strokes->findByRound($round) as $stroke) {
                if ($stroke->getPosition() > $sent) {
                    yield self::strokeEvent($stroke, true);
                    $sent = $stroke->getPosition();
                    $lastProgress = microtime(true);
                }
            }
            if (!$round->isAiDrawingFinished() && microtime(true) - $lastProgress > self::STALE_S) {
                $this->logger->warning('The drawing of round {id} stopped without finishing.', ['id' => $roundId->toRfc4122()]);
                $round->finishAiDrawing();
                $this->entityManager->flush();
            }
            if ($round->isAiDrawingFinished() || $round->isOver()) {
                yield new ServerEvent((string) json_encode(['strokes' => $sent]), type: 'finished');

                return;
            }
            yield new ServerEvent('', comment: 'drawing');
            usleep(self::FOLLOW_PAUSE_MS * 1000);
        }
    }

    private function waitUntil(float $time): void
    {
        $wait = $time - microtime(true);
        if ($this->penRhythm && $wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    private static function strokeEvent(Stroke $stroke, bool $animate): ServerEvent
    {
        return new ServerEvent((string) json_encode([
            'position' => $stroke->getPosition(),
            'points' => $stroke->getPoints(),
            'animate' => $animate,
        ]), type: 'stroke');
    }
}
