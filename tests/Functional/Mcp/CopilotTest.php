<?php

namespace App\Tests\Functional\Mcp;

use App\Game\Copilot;
use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
    use ScriptedModel;

    private KernelBrowser $client;
    private string $session;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client, word: 'girafe', strokes: [['neck', 'x25y45,x25y10'], ['head', 'x25y10,x30y8']]);
        self::mockTime();

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        $this->session = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $this->session);
        $this->rpc('notifications/initialized');
    }

    public function testTheCopilotHasThreeToolsAndAPrompt(): void
    {
        self::assertSame(['round_status', 'look_at_drawing', 'suggest_word'], array_column($this->rpc('tools/list')['tools'], 'name'));
        self::assertSame(['copilot'], array_column($this->rpc('prompts/list')['prompts'], 'name'));
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
        self::assertStringNotContainsString('girafe', $status['content'][0]['text'], 'The word to find is never given');

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

        self::assertSame([['hint', 'girafe']], array_map(static fn (array $event): array => [$event['type'], $event['data']['word']], self::readEvents($this->client, $round.'/hints')));
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

    public function testAGameWithACopilotWaitsInTheLobbyUntilThePlayerStartsIt(): void
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'copilot' => '1']);
        self::assertResponseRedirects();
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/games/[0-9a-f-]{36}/lobby$#', $lobby);
        $game = substr($lobby, 0, -\strlen('/lobby'));

        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.copilot-status', 'En attente de ton copilote');
        self::assertSelectorTextContains('.lobby-steps', 'claude mcp add --transport http draw-it http://localhost/mcp');
        $this->client->request('GET', $game.'/copilot');
        self::assertSame(['connected' => false, 'name' => null], json_decode((string) $this->client->getResponse()->getContent(), true));

        // The copilot calls: the player sees it is connected, by the name of its MCP client.
        $status = json_decode($this->call('round_status')['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('waiting for the player to start the game', $status['state']);
        self::assertStringContainsString('not started', $this->call('look_at_drawing')['content'][0]['text']);
        $this->client->request('GET', $game.'/copilot');
        self::assertSame(['connected' => true, 'name' => 'test'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', $lobby);
        self::assertSelectorTextContains('.copilot-status.connected', 'Copilote connecté : test');

        // The clock starts when the player starts the game.
        $this->client->submitForm('Commencer la partie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.round-number', 'Manche 1 / 6');
        self::assertSelectorTextContains('.copilot-badge.connected', 'test');
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
        $this->client->submitForm('Nouvelle partie');

        self::assertTrue($this->roundStatus()['stop']);
        self::assertStringContainsString('left the game', $this->call('suggest_word', ['word' => 'girafe'])['content'][0]['text']);
    }

    public function testTheCopilotIsToldToStopWhenNoRoundFollowsForTwoMinutes(): void
    {
        $this->startRound('ai');
        Clock::get()->sleep(62);
        self::assertFalse($this->roundStatus()['stop'], 'The player reads the result of the round');

        Clock::get()->sleep(120);
        $status = $this->roundStatus();
        self::assertTrue($status['stop']);
        self::assertStringContainsString('probably stopped', $status['what_you_can_do']);
    }

    public function testTheCopilotIsToldToStopWhenThePlayerPlaysAlone(): void
    {
        $this->startRound('ai');
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'drawnBy' => 'player']);

        $status = $this->roundStatus();
        self::assertTrue($status['stop']);
        self::assertStringContainsString('alone', $status['what_you_can_do']);
    }

    public function testAGameWithoutACopilotIsNotSeenNorHelped(): void
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'drawnBy' => 'ai']);
        $round = (string) $this->client->getResponse()->headers->get('Location');
        self::readEvents($this->client, $round.'/drawing');

        foreach ([['look_at_drawing', []], ['suggest_word', ['word' => 'girafe']]] as [$tool, $arguments]) {
            $result = $this->call($tool, $arguments);
            self::assertTrue($result['isError'], $tool);
            self::assertStringContainsString('alone', $result['content'][0]['text']);
        }
        self::assertSame([], self::readEvents($this->client, $round.'/hints'), 'No hint in the margin');
    }

    /**
     * Opens a game with a copilot, and returns the URL of its lobby.
     */
    private function openLobby(): string
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'copilot' => '1']);
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * Starts a game with a copilot, past the lobby, and returns the URL of its first round.
     */
    private function startRound(string $drawnBy): string
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'drawnBy' => $drawnBy, 'copilot' => '1']);
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('POST', substr($lobby, 0, -\strlen('/lobby')).'/rounds');
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @return array<string, mixed>
     */
    private function roundStatus(): array
    {
        return json_decode($this->call('round_status')['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function answer(string $round, string $word): void
    {
        $this->client->request('POST', $round.'/answers', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['word' => $word], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = []): array
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed> The result of the request; empty for a notification
     */
    private function rpc(string $method, ?array $params = null): array
    {
        $notification = str_starts_with($method, 'notifications/');
        $message = ['jsonrpc' => '2.0', 'method' => $method] + ($notification ? [] : ['id' => 1]) + (null === $params ? [] : ['params' => $params]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (isset($this->session)) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }

        $this->client->request('POST', '/mcp', server: $server, content: json_encode($message, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        if ($notification) {
            return [];
        }
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));

        return $response['result'];
    }
}
