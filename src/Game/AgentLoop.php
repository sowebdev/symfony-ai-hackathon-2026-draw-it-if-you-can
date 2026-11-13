<?php

namespace App\Game;

use App\Entity\Guess;
use App\Entity\Hint;
use App\Entity\Tip;
use App\Entity\Round;
use App\Repository\GuessRepository;
use App\Repository\HintRepository;
use App\Repository\TipRepository;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * What the agent connected over MCP does, pushed to the browser as it comes: the hints and tips
 * of a copilot, the answers of the rival in a duel, and "rival_found" when the rival finds the word.
 * They are written by another request, the MCP one, so the stream reads them from the database.
 */
final readonly class AgentLoop
{
    private const int PAUSE_MS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundRepository $rounds,
        private HintRepository $hints,
        private TipRepository $tips,
        private GuessRepository $guesses,
        #[Autowire('%game.agent_stream_max_s%')]
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
        $sentHint = 0;
        $sentTip = 0;
        $sentAnswer = 0;

        while (true) {
            $this->entityManager->clear();
            $round = $this->rounds->find($roundId) ?? throw new \LogicException('The round is gone.');
            foreach ($this->hints->findByRound($round) as $hint) {
                if ($hint->getId() > $sentHint) {
                    yield self::hintEvent($hint);
                    $sentHint = (int) $hint->getId();
                }
            }
            foreach ($this->tips->findByRound($round) as $tip) {
                if ($tip->getId() > $sentTip) {
                    yield self::tipEvent($tip);
                    $sentTip = (int) $tip->getId();
                }
            }
            foreach ($this->guesses->findByRound($round) as $guess) {
                if (Author::Agent === $guess->getAuthor() && $guess->getId() > $sentAnswer) {
                    yield self::answerEvent($guess);
                    $sentAnswer = (int) $guess->getId();
                }
            }
            if ($round->isFoundByRival()) {
                yield new ServerEvent((string) json_encode(['foundAfterMs' => $round->getFoundAfterMs()]), type: 'rival_found');

                return;
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

    private static function tipEvent(Tip $tip): ServerEvent
    {
        return new ServerEvent((string) json_encode(['id' => $tip->getId(), 'text' => $tip->getText()]), type: 'tip');
    }

    private static function answerEvent(Guess $guess): ServerEvent
    {
        return new ServerEvent((string) json_encode(['id' => $guess->getId(), 'word' => $guess->getWord(), 'right' => $guess->isAccepted()]), type: 'answer');
    }
}
