<?php

namespace App\Game;

use App\Entity\Hint;
use App\Entity\Round;
use App\Repository\HintRepository;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * The hints of the copilot, pushed to the browser as they come: they are written by another
 * request, the MCP one, so the stream reads them from the database.
 */
final readonly class HintLoop
{
    private const int PAUSE_MS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepository $rounds,
        private HintRepository $hints,
        #[Autowire('%game.hint_stream_max_s%')]
        private int $maxDurationS,
    ) {
    }

    /**
     * @return \Generator<ServerEvent>
     */
    public function run(Round $round): \Generator
    {
        set_time_limit(0);
        $roundId = $round->getId();
        // The stream ends with the round, or before: the browser reconnects by itself.
        $deadline = microtime(true) + min($this->maxDurationS, ($round->remainingMs() + Round::GRACE_MS) / 1000);
        $sent = 0;

        while (true) {
            $this->entityManager->clear();
            $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
            foreach ($this->hints->findByRound($round) as $hint) {
                if ($hint->getId() > $sent) {
                    yield self::hintEvent($hint);
                    $sent = (int) $hint->getId();
                }
            }
            if ($round->isOver() || microtime(true) >= $deadline) {
                return;
            }
            yield new ServerEvent('', comment: 'listening');
            usleep(self::PAUSE_MS * 1000);
        }
    }

    private static function hintEvent(Hint $hint): ServerEvent
    {
        return new ServerEvent((string) json_encode(['id' => $hint->getId(), 'word' => $hint->getWord()]), type: 'hint');
    }
}
