<?php

namespace App\Game;

use App\Entity\Guess;
use App\Entity\Round;
use App\Repository\GuessRepository;

/**
 * The rules of the rival in a duel: an agent connected over MCP guesses the same drawing as the
 * side of the player, whoever draws, and wins the points of a word it finds first. Every answer goes
 * through the circuit of the referee, like the player's (Referee): judged accepted, warm or refused, and
 * settled in the order the words were said, so that the side that said the word first wins it. It
 * answers once every few seconds at most.
 */
final readonly class Duel
{
    /** The rival waits this long between two answers. */
    public const int ANSWER_GAP_MS = 3000;

    public function __construct(
        private GuessRepository $guesses,
        private Referee $referee,
    ) {
    }

    /**
     * @throws AgentRefusal When the round does not take this answer, with the reason for the agent
     */
    public function answer(Round $round, string $word): Guess
    {
        $word = trim($word);
        if ('' === $word || mb_strlen($word) > 100) {
            throw new AgentRefusal('An answer is one word or a short phrase, at most 100 characters.');
        }
        $game = $round->getGame();
        if (!$game->isDuel()) {
            throw new AgentRefusal('This game is not a duel: you may not answer. As a copilot, whisper with suggest_word.');
        }
        if ($game->hasLeft()) {
            throw new AgentRefusal('The player left the game: stop playing.');
        }
        if ($round->isOver()) {
            throw new AgentRefusal('This round is over. Wait for the next one with wait_for_your_turn.');
        }

        $mine = $this->answersOf($round);
        $last = end($mine);
        $now = $round->elapsedMs();
        if (false !== $last && $now - $last->getSaidAfterMs() < self::ANSWER_GAP_MS) {
            throw new AgentRefusal(\sprintf('Too fast: one answer every %d seconds. Try again in %.1f s.', intdiv(self::ANSWER_GAP_MS, 1000), (self::ANSWER_GAP_MS - $now + $last->getSaidAfterMs()) / 1000));
        }
        foreach ($mine as $answer) {
            if (WordMatcher::normalize($answer->getWord()) === WordMatcher::normalize($word)) {
                throw new AgentRefusal(\sprintf('You already answered "%s" in this round, and it did not find the word.', $answer->getWord()));
            }
        }

        $guess = $this->referee->hear($round, $word, Author::Agent);
        $this->referee->judge($guess);

        return $guess;
    }

    /**
     * @return list<Guess>
     */
    private function answersOf(Round $round): array
    {
        return array_values(array_filter($this->guesses->findByRound($round), static fn (Guess $guess): bool => Author::Agent === $guess->getAuthor()));
    }
}
