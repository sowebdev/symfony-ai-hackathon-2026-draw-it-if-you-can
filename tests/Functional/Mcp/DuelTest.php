<?php

namespace App\Tests\Functional\Mcp;

use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The duel: an agent connected over MCP guesses the same drawings as the side of the player, and
 * wins the points of the words it finds first.
 */
final class DuelTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EventStream;
    use McpClient;
    use ScriptedModel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client, guesses: ['chien'], word: 'girafe', strokes: [['neck', 'x25y45,x25y10']]);
        self::mockTime();
        $this->connect();
    }

    public function testTheLobbyOfADuelAsksForARival(): void
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'mode' => 'duel']);
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('h1', 'Branche ton adversaire');
        self::assertSelectorTextContains('.lobby-steps', '/mcp__draw-it__duel');
        self::assertSelectorTextContains('.agent-status', 'En attente de ton adversaire');

        $status = $this->roundStatus();
        self::assertStringContainsString('rival', $status['you_are']);
        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.agent-status.connected', 'Adversaire connecté : Test');
    }

    public function testTheRivalChoosesAFunnyNickname(): void
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'mode' => 'duel']);
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('choose_nickname', $this->roundStatus()['what_you_can_do']);

        self::assertTrue($this->call('choose_nickname', ['nickname' => str_repeat('x', 25)])['isError']);
        self::assertTrue($this->call('choose_nickname', ['nickname' => " \n "])['isError']);
        $named = $this->call('choose_nickname', ['nickname' => "Picasso\ndu dimanche"]);
        self::assertStringContainsString('"Picasso du dimanche"', $named['content'][0]['text']);
        self::assertSame('Picasso du dimanche', $this->roundStatus()['your_nickname']);

        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.agent-status.connected', 'Adversaire connecté : Picasso du dimanche');
        $this->client->submitForm('Commencer la partie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.rival-score', 'Picasso du dimanche');
    }

    public function testTheRivalFindsTheDrawingOfTheAiFirstAndWinsItsPoints(): void
    {
        $round = $this->startRound('ai', 'duel');

        $wrong = $this->call('submit_answer', ['word' => 'zèbre']);
        self::assertFalse($wrong['isError'] ?? false);
        self::assertStringContainsString('wrong', $wrong['content'][0]['text']);
        Clock::get()->sleep(3);
        $right = $this->call('submit_answer', ['word' => 'Girafe']);
        self::assertStringContainsString('Right!', $right['content'][0]['text']);

        $events = self::readEvents($this->client, $round.'/agent');
        self::assertSame(['answer', 'answer', 'rival_found'], array_column($events, 'type'));
        self::assertSame(['zèbre', false], [$events[0]['data']['word'], $events[0]['data']['right']]);
        self::assertTrue($events[1]['data']['right']);

        $this->client->request('POST', $round.'/answers', server: ['CONTENT_TYPE' => 'application/json'], content: '{"word": "girafe"}');
        self::assertResponseStatusCodeSame(409, 'Too late for the player');

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-end h2', 'Test a trouvé !');
        self::assertSelectorTextContains('.round-end-points', 'points pour Test');
        self::assertSelectorTextContains('.duel-score', 'Toi 0');
        self::assertSelectorTextContains('.rival-score strong', '1000');
        self::assertSelectorTextContains('.rival-answers', 'zèbre');

        $status = $this->roundStatus();
        self::assertSame('agent', $status['found_by']);
        self::assertSame(['you' => 1000, 'the_player' => 0], $status['score']);
    }

    public function testTheRivalAnswersOnceEveryThreeSecondsAndNeverTwiceTheSame(): void
    {
        $this->startRound('ai', 'duel');

        $this->call('submit_answer', ['word' => 'zèbre']);
        $tooFast = $this->call('submit_answer', ['word' => 'lion']);
        self::assertTrue($tooFast['isError']);
        self::assertStringContainsString('Too fast', $tooFast['content'][0]['text']);

        Clock::get()->sleep(3);
        self::assertTrue($this->call('submit_answer', ['word' => 'Zèbres'])['isError'], 'The same word twice');
        self::assertFalse($this->call('submit_answer', ['word' => 'lion'])['isError'] ?? false);
    }

    public function testWhenThePlayerDrawsTheRivalPlaysAgainstTheAiOfThePlayer(): void
    {
        $round = $this->startRound('player', 'duel');
        $this->client->request('POST', $round.'/strokes', server: ['CONTENT_TYPE' => 'application/json'], content: '{"points": [[100, 100, 0], [500, 500, 300]]}');
        self::assertResponseStatusCodeSame(201);
        self::readEvents($this->client, $round.'/guesses');

        $status = $this->roundStatus();
        self::assertContains('chien', $status['wrong_answers_of_the_others'], 'The rival sees the guesses of the AI');
        self::assertStringContainsString('The player draws', $status['what_you_can_do']);
        self::assertSame('image', $this->call('look_at_drawing')['content'][1]['type']);

        $this->call('submit_answer', ['word' => 'girafe']);
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-end h2', 'Test a trouvé !');
        self::assertSelectorExists('.rival-answers li.accepted');
        self::assertSelectorTextNotContains('.guesses ol', 'girafe', 'The answers of the rival are not those of the AI');
    }

    public function testACopilotDoesNotAnswerAndARivalDoesNotWhisper(): void
    {
        $this->startRound('ai', 'duel');
        $whisper = $this->call('suggest_word', ['word' => 'girafe']);
        self::assertTrue($whisper['isError']);
        self::assertStringContainsString('duel', $whisper['content'][0]['text']);

        $this->startRound('ai', 'copilot');
        $answer = $this->call('submit_answer', ['word' => 'girafe']);
        self::assertTrue($answer['isError']);
        self::assertStringContainsString('not a duel', $answer['content'][0]['text']);
    }

    public function testAClientWithoutSessionPlaysUnderItsOwnName(): void
    {
        $round = $this->startRound('ai', 'duel');

        $status = json_decode($this->callStateless('round_status', 'claude-code')['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('playing', $status['state']);
        self::assertFalse($this->callStateless('submit_answer', 'claude-code', ['word' => 'zèbre'])['isError'] ?? false);

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.rival-score', 'Claude');
    }

    public function testTheFinalScoreOfADuelSaysWhoWon(): void
    {
        $round = $this->startRound('ai', 'duel');
        $game = null;
        for ($number = 1; $number <= 6; ++$number) {
            if (1 === $number % 2) {
                // The rival finds the drawings of the AI; the player draws nothing.
                $this->call('submit_answer', ['word' => 'girafe']);
            } else {
                Clock::get()->sleep(62);
            }
            $this->client->request('GET', $round);
            $game ??= (string) $this->client->getCrawler()->filter('form.leave')->attr('action');
            $game = preg_replace('#/leave$#', '', $game);
            $this->client->request('POST', $game.'/rounds');
            $round = (string) $this->client->getResponse()->headers->get('Location');
        }

        $this->client->followRedirect();
        self::assertSelectorTextContains('.final-score', 'Test gagne le duel.');
        self::assertSelectorTextContains('.duel-final', 'Toi 0 points, Test 3000 points');
        self::assertSelectorTextContains('table', 'Trouvé par');
    }
}
