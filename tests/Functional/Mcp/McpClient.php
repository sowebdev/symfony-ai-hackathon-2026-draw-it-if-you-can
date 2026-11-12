<?php

namespace App\Tests\Functional\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * An agent connected over MCP, as a client such as Claude Code would talk to the game: JSON-RPC
 * over HTTP on /mcp, in a session opened by "initialize", or without one on the stateless
 * protocol (2026-07-28) that recent clients speak.
 */
trait McpClient
{
    private KernelBrowser $client;
    private string $session;

    /**
     * Opens a session under the name of an MCP client, and makes it the one that calls the tools.
     */
    private function connect(string $name = 'test'): string
    {
        unset($this->session);
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => $name, 'version' => '1']]);
        $this->session = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $this->session);
        $this->rpc('notifications/initialized');

        return $this->session;
    }

    /**
     * A tool called on the stateless protocol: no session, the client says who it is with each request.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callStateless(string $tool, string $clientName, array $arguments = []): array
    {
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => $clientName, 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments, '_meta' => $meta]];
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => $tool,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));

        return $response['result'];
    }

    /**
     * @return array<string, mixed>
     */
    private function roundStatus(): array
    {
        return json_decode($this->call('round_status')['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
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

    /**
     * Starts a game with an agent, past the lobby, and returns the URL of its first round.
     */
    private function startRound(string $drawnBy, string $mode = 'copilot'): string
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'drawnBy' => $drawnBy, 'mode' => $mode]);
        $lobby = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('POST', substr($lobby, 0, -\strlen('/lobby')).'/rounds');
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }
}
