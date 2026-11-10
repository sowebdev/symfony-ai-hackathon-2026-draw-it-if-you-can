<?php

namespace App\Game;

use App\Ai\DrawsStrokes;
use App\Drawing\StrokeTracer;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Repository\RoundRepository;
use App\Repository\StrokeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * The AI draws while the player guesses. Runs inside one server-sent events response: the first
 * request draws and stores each stroke; a request that comes later (a reload) replays the stored
 * strokes, then follows the new ones until the drawing is finished.
 */
final readonly class DrawingLoop
{
    /** Pause of the pen between two strokes, on top of the time it takes to draw one. */
    private const int PEN_LIFT_MS = 300;

    /** Pause while following a drawing run by another request. */
    private const int FOLLOW_PAUSE_MS = 300;

    /** A drawing without a new stroke for this long has died with its request: it is over. */
    private const int STALE_S = 60;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepository $rounds,
        private StrokeRepository $strokes,
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
        // Bounded by the stroke limit of the drawer, not by PHP's 30 s, which a slow model exceeds.
        set_time_limit(0);
        $roundId = $round->getId();
        $seed = crc32($roundId->toRfc4122());
        $nextAt = 0.0;
        $position = 0;

        try {
            foreach ($this->drawer->draw($round->getTargetWord(), $round->getGame()->getTheme()) as $written) {
                // The player may have found the word, or given up, in another request.
                $this->entityManager->clear();
                $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
                if ($round->isOver()) {
                    break;
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
        }

        $this->entityManager->clear();
        $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
        $round->finishAiDrawing();
        $this->entityManager->flush();
        yield new ServerEvent((string) json_encode(['strokes' => $position]), type: 'finished');
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
