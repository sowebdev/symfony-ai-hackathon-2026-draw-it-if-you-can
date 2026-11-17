<?php

namespace App\Tests\Functional\Mcp;

use App\Game\Copilot;
use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The copilot: an agent connected over MCP, as a client such as Claude Code would talk to it,
 * JSON-RPC over HTTP on /mcp.
 */
final class CopilotTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EventStream;
    use McpClient;
    use ScriptedModel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client, guesses: ['cheval', 'zèbre'], word: 'girafe', strokes: [['neck', 'x25y45,x25y10'], ['head', 'x25y10,x30y8']]);
        self::mockTime();
        $this->connect();
    }

    public function testTheAgentHasSevenToolsAndTwoPrompts(): void
    {
        self::assertSame(['round_status', 'wait_for_your_turn', 'look_at_drawing', 'suggest_word', 'give_tip', 'choose_nickname', 'submit_answer'], array_column($this->rpc('tools/list')['tools'], 'name'));
        self::assertSame(['copilot', 'duel'], array_column($this->rpc('prompts/list')['prompts'], 'name'));
    }

    public function testTheCopilotSeesTheDrawingButNotTheWord(): void
    {
        $round = $this->startRound('ai');
        self::readEvents($this->client, $round.'/drawing');
        $this->answer($round, 'cheval');

        $status = $this->call('round_status');
        self::assertFalse($status['isError'] ?? false);
        $state = json_decode($status['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('ai', $state['drawn_by']);
        self::assertSame('playing', $state['state']);
        self::assertSame(2, $state['strokes']);
        self::assertSame(['cheval'], $state['wrong_answers']);
        self::assertSame('French', $state['language']);
        self::assertStringNotContainsString('girafe', $status['content'][0]['text'], 'The word to find is not given during the round');

        $drawing = $this->call('look_at_drawing');
        self::assertSame(['text', 'image'], array_column($drawing['content'], 'type'));
        self::assertSame('image/png', $drawing['content'][1]['mimeType']);
        self::assertStringStartsWith("\x89PNG", (string) base64_decode($drawing['content'][1]['data'], true));
    }

    public function testAHintIsShownToThePlayerWhoDecides(): void
    {
        $round = $this->startRound('ai');

        $result = $this->call('suggest_word', ['word' => 'girafe']);
        self::assertFalse($result['isError'] ?? false);
        self::assertStringContainsString('4 hints left', $result['content'][0]['text']);

        self::assertSame([['hint', 'girafe']], array_map(static fn (array $event): array => [$event['type'], $event['data']['word']], self::readEvents($this->client, $round.'/agent')));
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.hints button', 'girafe');
        self::assertSelectorNotExists('.round-end', 'A hint does not find the word: only the player answers');
    }

    public function testTheCopilotGivesAFewDifferentHintsPerRound(): void
    {
        $this->startRound('ai');

        $this->call('suggest_word', ['word' => 'girafe']);
        self::assertTrue($this->call('suggest_word', ['word' => 'Girafes'])['isError'], 'The same word twice');
        foreach (['zèbre', 'cheval', 'âne', 'lama'] as $word) {
            self::assertFalse($this->call('suggest_word', ['word' => $word])['isError'] ?? false);
        }

        $refused = $this->call('suggest_word', ['word' => 'chameau']);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString(\sprintf('the %d hints', Copilot::MAX_HINTS), $refused['content'][0]['text']);
    }

    public function testNoHintWhenThePlayerDrawsNorAfterTheTime(): void
    {
        $this->startRound('player');
        self::assertStringContainsString('The player draws', $this->call('suggest_word', ['word' => 'girafe'])['content'][0]['text']);

        $this->startRound('ai');
        Clock::get()->sleep(62);
        self::assertStringContainsString('over', $this->call('suggest_word', ['word' => 'girafe'])['content'][0]['text']);
    }

    public function testWhenThePlayerDrawsTheCopilotKnowsTheWordAndWhatTheAiSees(): void
    {
        $round = $this->startRound('player');
        $this->drawStroke($round);
        self::readEvents($this->client, $round.'/guesses');

        $status = $this->roundStatus();
        self::assertSame('player', $status['drawn_by']);
        self::assertSame('girafe', $status['the_word_the_player_draws'], 'The player knows the word: their copilot may too');
        self::assertContains('cheval', $status['wrong_guesses_of_the_ai']);
        self::assertSame([], $status['your_tips']);
        self::assertStringContainsString('give_tip', $status['what_you_can_do']);
        self::assertSame('image', $this->call('look_at_drawing')['content'][1]['type'], 'The copilot sees the drawing of the player');
    }

    public function testTheCopilotOfAPlayerWhoDrawsIsWokenByTheGuessesOfTheAi(): void
    {
        $round = $this->startRound('player');
        $this->roundStatus();

        $this->drawStroke($round);
        self::readEvents($this->client, $round.'/guesses');

        self::assertSame('the AI made new guesses', $this->waitForYourTurn()['news']);
    }

    public function testATipIsShownToThePlayerWhoDraws(): void
    {
        $round = $this->startRound('player');
        $this->call('choose_nickname', ['nickname' => 'Crayon Fou']);

        $result = $this->call('give_tip', ['tip' => "  Allonge le cou, l'IA voit un canard  "]);
        self::assertFalse($result['isError'] ?? false);
        self::assertStringContainsString('shown to the player', $result['content'][0]['text']);
        self::assertSame(["Allonge le cou, l'IA voit un canard"], $this->roundStatus()['your_tips']);

        self::assertSame([['tip', "Allonge le cou, l'IA voit un canard"]], array_map(static fn (array $event): array => [$event['type'], $event['data']['text']], self::readEvents($this->client, $round.'/agent')));
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.tips h2', 'Les conseils de Crayon Fou');
        self::assertSelectorTextContains('.tips li', "Allonge le cou, l'IA voit un canard");
    }

    public function testTipsComeAFewSecondsApartWithoutALimitPerRound(): void
    {
        $round = $this->startRound('player');
        self::assertFalse($this->call('give_tip', ['tip' => 'Dessine le cou'])['isError'] ?? false);

        $tooFast = $this->call('give_tip', ['tip' => 'Ajoute des taches']);
        self::assertTrue($tooFast['isError']);
        self::assertStringContainsString('Too fast', $tooFast['content'][0]['text']);

        foreach (range(1, Copilot::MAX_HINTS + 1) as $tip) {
            Clock::get()->sleep(Copilot::TIP_GAP_MS / 1000);
            self::assertFalse($this->call('give_tip', ['tip' => "Conseil $tip"])['isError'] ?? false, "Tip $tip");
        }

        self::assertTrue($this->call('give_tip', ['tip' => ''])['isError'], 'An empty tip');
        Clock::get()->sleep(Copilot::TIP_GAP_MS / 1000);
        self::assertTrue($this->call('give_tip', ['tip' => str_repeat('a', Copilot::TIP_MAX_LENGTH + 1)])['isError'], 'A tip too long');

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.tips li:first-child', 'Conseil '.(Copilot::MAX_HINTS + 1), 'The newest tip on top');
    }

    public function testNoTipWhenTheAiDrawsNorInADuel(): void
    {
        $this->startRound('ai');
        self::assertStringContainsString('suggest_word', $this->call('give_tip', ['tip' => 'Dessine le cou'])['content'][0]['text']);

        $this->startRound('player', 'duel');
        self::assertStringContainsString('duel', $this->call('give_tip', ['tip' => 'Dessine le cou'])['content'][0]['text']);
        self::assertArrayNotHasKey('the_word_the_player_draws', $this->roundStatus(), 'A rival never gets the word');
    }

    public function testAGameWithACopilotWaitsInTheLobbyUntilThePlayerStartsIt(): void
    {
        $this->client->request('POST', '/fr/games', ['mode' => 'copilot']);
        self::assertResponseRedirects();
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/games/[0-9a-f-]{36}/lobby$#', $lobby);
        $game = substr($lobby, 0, -\strlen('/lobby'));

        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.agent-status', 'En attente de ton copilote');
        self::assertSelectorTextContains('.lobby-steps', 'claude mcp add --scope user --transport http draw-it http://localhost/mcp');
        self::assertSelectorTextContains('.lobby-vision', 'doit voir les images');
        $this->client->request('GET', $game.'/agent');
        self::assertSame(['connected' => false, 'name' => null], json_decode((string) $this->client->getResponse()->getContent(), true));

        // The copilot calls: the player sees it is connected, by the name of its MCP client.
        $status = json_decode($this->call('round_status')['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('waiting for the player to start the game', $status['state']);
        self::assertStringContainsString('not started', $this->call('look_at_drawing')['content'][0]['text']);
        $this->client->request('GET', $game.'/agent');
        self::assertSame(['connected' => true, 'name' => 'Test'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.agent-status.connected', 'Copilote connecté : Test');

        // The clock starts when the player starts the game.
        $this->client->submitForm('Commencer la partie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.round-number', 'Manche 1 / 6');
        self::assertSelectorTextContains('.copilot-badge.connected', 'Test');
        self::assertSelectorTextContains('.clock strong', '60');
        $this->client->request('GET', $lobby);
        self::assertResponseRedirects(message: 'The lobby is behind once the game started');
    }

    public function testTheCopilotIsToldToStopWhenThePlayerLeaves(): void
    {
        $lobby = $this->openLobby();
        self::assertFalse($this->roundStatus()['stop']);

        $this->client->request('POST', substr($lobby, 0, -\strlen('/lobby')).'/leave');
        self::assertResponseRedirects('/fr');

        $status = $this->roundStatus();
        self::assertTrue($status['stop']);
        self::assertSame('the player left', $status['state']);
    }

    public function testTheCopilotIsToldToStopWhenThePlayerLeavesDuringARound(): void
    {
        $round = $this->startRound('ai');
        $this->client->request('GET', $round);
        $this->client->submitForm('Quitter la partie');

        self::assertTrue($this->roundStatus()['stop']);
        self::assertStringContainsString('left the game', $this->call('suggest_word', ['word' => 'girafe'])['content'][0]['text']);
    }

    public function testTheCopilotIsToldToStopWhenNoRoundFollowsForTwoMinutes(): void
    {
        $this->startRound('ai');
        Clock::get()->sleep(62);
        $status = $this->roundStatus();
        self::assertFalse($status['stop'], 'The player reads the result of the round');

        Clock::get()->sleep(120);
        $status = $this->roundStatus();
        self::assertSame('girafe', $status['the_word_was'], 'The round is over: the word is given');
        self::assertTrue($status['stop']);
        self::assertStringContainsString('probably stopped', $status['what_you_can_do']);
    }

    public function testTheCopilotIsToldToStopWhenThePlayerPlaysAlone(): void
    {
        $this->startRound('ai');
        $this->client->request('POST', '/fr/games', ['drawnBy' => 'player']);

        $status = $this->roundStatus();
        self::assertTrue($status['stop']);
        self::assertStringContainsString('alone', $status['what_you_can_do']);
    }

    public function testAGameWithoutACopilotIsNotSeenNorHelped(): void
    {
        $round = self::startFirstRound($this->client, 'fr', ['drawnBy' => 'ai']);
        self::readEvents($this->client, $round.'/drawing');

        foreach ([['look_at_drawing', []], ['suggest_word', ['word' => 'girafe']], ['give_tip', ['tip' => 'Dessine le cou']]] as [$tool, $arguments]) {
            $result = $this->call($tool, $arguments);
            self::assertTrue($result['isError'], $tool);
            self::assertStringContainsString('alone', $result['content'][0]['text']);
        }
        self::assertSame([], self::readEvents($this->client, $round.'/agent'), 'No hint in the margin');
    }

    /**
     * Opens a game with a copilot, and returns the URL of its lobby.
     */
    private function openLobby(): string
    {
        $this->client->request('POST', '/fr/games', ['mode' => 'copilot']);
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function drawStroke(string $round): void
    {
        $this->client->request('POST', $round.'/strokes', server: ['CONTENT_TYPE' => 'application/json'], content: '{"points": [[100, 100, 0], [500, 500, 300]]}');
        self::assertResponseStatusCodeSame(201);
    }

    private function answer(string $round, string $word): void
    {
        $this->client->request('POST', $round.'/answers', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['word' => $word], \JSON_THROW_ON_ERROR));
    }
}
