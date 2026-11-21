<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Round;
use App\Game\Author;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use App\Game\Verdict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The round is settled in the order the words were said, not in the order their verdicts came back.
 */
final class RoundSettleTest extends TestCase
{
    use ClockSensitiveTrait;

    private const int LIMIT_MS = 10_000;

    private Round $round;

    protected function setUp(): void
    {
        self::mockTime('2026-11-21 10:00:00');
        $this->round = new Round(new Game(Language::English, 6, DrawnBy::Ai, Mode::Duel), 1, 'cat', 60_000, Theme::Animals);
    }

    public function testAnExactWordWaitsForASynonymSaidBeforeItAndStillWithTheJudge(): void
    {
        $synonym = $this->said('kitty', Author::Player, 10_000);
        $exact = $this->said('cat', Author::Agent, 12_000, Verdict::Accepted);

        self::assertTrue($this->round->settle([$synonym, $exact], $exact, self::LIMIT_MS), 'The exact word waits');
        self::assertFalse($this->round->isFound());

        $synonym->judged(Verdict::Accepted);
        self::assertFalse($this->round->settle([$synonym, $exact], $synonym, self::LIMIT_MS));
        self::assertTrue($synonym->isAccepted(), 'The word said first finds the round');
        self::assertFalse($exact->isAccepted());
        self::assertSame(10_000, $this->round->getFoundAfterMs());
    }

    public function testWhenTheWordSaidBeforeIsOnlyWarmTheExactWordFinds(): void
    {
        $close = $this->said('kitten', Author::Player, 10_000, Verdict::Warm);
        $exact = $this->said('cat', Author::Agent, 12_000, Verdict::Accepted);

        self::assertFalse($this->round->settle([$close, $exact], $exact, self::LIMIT_MS));
        self::assertTrue($exact->isAccepted());
        self::assertTrue($this->round->isFoundByRival());
    }

    public function testAWordSaidAfterAPendingOneDoesNotHoldItBack(): void
    {
        $exact = $this->said('cat', Author::Player, 10_000, Verdict::Accepted);
        $later = $this->said('kitty', Author::Agent, 12_000);

        self::assertFalse($this->round->settle([$exact, $later], $exact, self::LIMIT_MS));
        self::assertTrue($exact->isAccepted());
    }

    public function testAWordWhoseJudgeIsGoneIsRefusedAndNoLongerHoldsTheOthersBack(): void
    {
        $lost = $this->said('kitty', Author::Player, 10_000);
        $exact = $this->said('cat', Author::Agent, 12_000, Verdict::Accepted);
        self::mockTime('2026-11-21 10:00:20.500');

        self::assertFalse($this->round->settle([$lost, $exact], $exact, self::LIMIT_MS));
        self::assertSame(Verdict::Refused, $lost->getVerdict());
        self::assertTrue($exact->isAccepted());

        $lost->judged(Verdict::Accepted);
        self::assertSame(Verdict::Refused, $lost->getVerdict(), 'A late verdict changes nothing');
    }

    private function said(string $word, Author $author, int $afterMs, ?Verdict $verdict = null): Guess
    {
        $guess = new Guess($this->round, $word, $afterMs, $author);
        if (null !== $verdict) {
            $guess->judged($verdict);
        }

        return $guess;
    }
}
