<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testHomeStartsANewGame(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('New game');
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('canvas[data-canvas-target="canvas"]');
    }

    public function testStoresStrokesInOrder(): void
    {
        $game = $this->createGame();

        self::assertSame(['position' => 1], $this->addStroke($game, [[10, 10, 0], [20, 20, 40]]));
        self::assertSame(['position' => 2], $this->addStroke($game, [[30, 30, 900]]));

        $this->client->request('GET', $game.'/strokes');
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['position' => 1, 'points' => [[10, 10, 0], [20, 20, 40]]],
            ['position' => 2, 'points' => [[30, 30, 900]]],
        ], $this->json());
    }

    public function testRejectsAStrokeOutsideTheCanvas(): void
    {
        $game = $this->createGame();

        $this->postJson($game.'/strokes', ['points' => [[10, 1001, 0]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Point 0 is outside the canvas.'], $this->json());
    }

    public function testRejectsAPayloadWithoutPoints(): void
    {
        $game = $this->createGame();

        $this->postJson($game.'/strokes', []);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUndoRemovesTheLastStrokeOnly(): void
    {
        $game = $this->createGame();
        $this->addStroke($game, [[10, 10, 0]]);
        $this->addStroke($game, [[20, 20, 100]]);

        $this->client->request('DELETE', $game.'/strokes/last');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $game.'/strokes');
        self::assertSame([['position' => 1, 'points' => [[10, 10, 0]]]], $this->json());

        // The next stroke takes the freed position.
        self::assertSame(['position' => 2], $this->addStroke($game, [[30, 30, 200]]));
    }

    public function testUndoWithoutStrokeIsNotFound(): void
    {
        $game = $this->createGame();

        $this->client->request('DELETE', $game.'/strokes/last');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStrokesOfAnUnknownGameAreNotFound(): void
    {
        $this->client->request('GET', '/games/019a6e2c-0000-7000-8000-000000000000/strokes');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStrokesOfAnotherGameAreNotMixedIn(): void
    {
        $game = $this->createGame();
        $other = $this->createGame();
        $this->addStroke($other, [[10, 10, 0]]);

        $this->client->request('GET', $game.'/strokes');

        self::assertSame([], $this->json());
    }

    /**
     * Creates a game and returns its URL.
     */
    private function createGame(): string
    {
        $this->client->request('POST', '/games');
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @param list<array{int, int, int}> $points
     *
     * @return array<mixed>
     */
    private function addStroke(string $game, array $points): array
    {
        $this->postJson($game.'/strokes', ['points' => $points]);
        self::assertResponseStatusCodeSame(201);

        return $this->json();
    }

    /**
     * @param array<mixed> $payload
     */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
