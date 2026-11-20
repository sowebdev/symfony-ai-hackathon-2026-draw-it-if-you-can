<?php

namespace App\Tests\Functional\Controller;

use App\Repository\RoundRepository;
use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A game against the clock: six rounds, the AI and the player take turns, the points of the
 * gauge go to a team score.
 */
final class GameFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EventStream;
    use ScriptedModel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The AI guesses a near word when the player draws.
        self::scriptModel($this->client, ['kitten'], strokes: [['body', 'x10y10,x40y40']]);
        self::mockTime();
    }

    public function testSixRoundsInTurnsThenTheFinalScore(): void
    {
        // The AI draws first, by default.
        $round = $this->startGame();
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-number', 'Round 1 of 6');
        self::assertSelectorTextContains('.team-score strong', '0');
        self::assertSelectorTextContains('.clock strong', '60');
        self::assertSelectorExists('.topbar .leave-button');
        self::assertSelectorExists('.umpire .referee.on-chair', 'Alone, the walrus referees from his chair');
        self::assertSelectorExists('[data-round-timer-gauge-value="[[0,1000],[10000,1000],[60000,100]]"]');
        self::assertSelectorNotExists('.round-end');

        for ($number = 1; $number <= 6; ++$number) {
            if (1 === $number % 2) {
                // The AI draws: the player finds the word after 35 s.
                self::assertSelectorTextContains('.target', 'What is Olaf drawing?');
                self::wait(35);
                $this->answer($round, 'cat');
                self::assertResponseStatusCodeSame(201);
            } else {
                // The player draws: nobody finds the word in time.
                self::assertSelectorTextContains('.target strong', 'cat');
                self::wait(62);
            }

            $this->client->request('GET', $round);
            self::assertSelectorTextContains('.round-end h2', 1 === $number % 2 ? 'Found it!' : "Time's up!");
            self::assertSelectorTextContains('.round-end-word strong', 'cat');
            self::assertSelectorTextContains('.round-end-points', 1 === $number % 2 ? '+550 points' : '+0 points');
            self::assertSelectorNotExists('.clock');

            if ($number < 6) {
                self::assertSelectorTextContains('.round-end button', 1 === $number % 2 ? 'your turn to draw' : 'Olaf draws');
                $this->client->submitForm(1 === $number % 2 ? 'Next round: your turn to draw' : 'Next round: Olaf draws');
                $round = (string) $this->client->getResponse()->headers->get('Location');
                $this->client->request('GET', $round);
                self::assertSelectorTextContains('.round-number', 'Round '.($number + 1).' of 6');
            }
        }

        $this->client->clickLink('See the final score');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.final-score strong', '1650');
        self::assertCount(6, $this->client->getCrawler()->filter('.game-over tbody tr'));
        self::assertSelectorTextContains('.game-over tbody tr:nth-child(2)', 'not found');
    }

    public function testTheNextRoundWaitsForTheEndOfThisOne(): void
    {
        $round = $this->startGame();
        $game = $this->gameOf($round);

        $this->client->request('POST', $game.'/rounds');
        self::assertResponseRedirects($round, message: 'A round being played is not skipped');

        $this->client->request('GET', $game);
        self::assertResponseRedirects($round, message: 'No final score before the last round');
    }

    public function testNothingCountsOnceTheTimeIsUp(): void
    {
        $round = $this->startGame();
        $drawn = self::startFirstRound($this->client, 'en', ['drawnBy' => 'player']);

        self::wait(62);

        $this->answer($round, 'cat');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', $drawn.'/strokes', server: ['CONTENT_TYPE' => 'application/json'], content: '{"points":[[1,1,0]]}');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('DELETE', $drawn.'/strokes/last');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['over'], array_column(self::readEvents($this->client, $drawn.'/guesses'), 'type'));
    }

    public function testAWordSentInTheLastSecondStillCounts(): void
    {
        $round = $this->startGame();

        self::wait(60.5);
        $this->answer($round, 'cat');

        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-end-points', '+100 points');
    }

    public function testTheFrenchGameSpeaksFrench(): void
    {
        $round = self::startFirstRound($this->client, 'fr');
        $this->client->request('POST', $round.'/give-up');

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-number', 'Manche 1 / 6');
        self::assertSelectorTextContains('.round-end h2', 'Pas cette fois');
        self::assertSelectorTextContains('.round-end button', 'Manche suivante : à toi de dessiner');
    }

    private function startGame(): string
    {
        return self::startFirstRound($this->client);
    }

    private function gameOf(string $round): string
    {
        $found = self::getContainer()->get(RoundRepository::class)->find(basename($round)) ?? throw new \LogicException('No round at '.$round);

        return '/games/'.$found->getGame()->getId()->toRfc4122();
    }

    private static function wait(float $seconds): void
    {
        Clock::get()->sleep($seconds);
    }

    private function answer(string $round, string $word): void
    {
        $this->client->request('POST', $round.'/answers', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['word' => $word], \JSON_THROW_ON_ERROR));
    }
}
