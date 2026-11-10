<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoundControllerTest extends WebTestCase
{
    use ScriptedModel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testStoresStrokesInOrder(): void
    {
        $round = $this->startRound();

        self::assertSame(['position' => 1], $this->addStroke($round, [[10, 10, 0], [20, 20, 40]]));
        self::assertSame(['position' => 2], $this->addStroke($round, [[30, 30, 900]]));

        $this->client->request('GET', $round.'/strokes');
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['position' => 1, 'points' => [[10, 10, 0], [20, 20, 40]]],
            ['position' => 2, 'points' => [[30, 30, 900]]],
        ], $this->json());
    }

    public function testRejectsAStrokeOutsideTheCanvas(): void
    {
        $round = $this->startRound();

        $this->postJson($round.'/strokes', ['points' => [[10, 1001, 0]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Point 0 is outside the canvas.'], $this->json());
    }

    public function testRejectsAPayloadWithoutPoints(): void
    {
        $round = $this->startRound();

        $this->postJson($round.'/strokes', []);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUndoRemovesTheLastStrokeOnly(): void
    {
        $round = $this->startRound();
        $this->addStroke($round, [[10, 10, 0]]);
        $this->addStroke($round, [[20, 20, 100]]);

        $this->client->request('DELETE', $round.'/strokes/last');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $round.'/strokes');
        self::assertSame([['position' => 1, 'points' => [[10, 10, 0]]]], $this->json());

        // The next stroke takes the freed position.
        self::assertSame(['position' => 2], $this->addStroke($round, [[30, 30, 200]]));
    }

    public function testUndoWithoutStrokeIsNotFound(): void
    {
        $round = $this->startRound();

        $this->client->request('DELETE', $round.'/strokes/last');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStrokesOfAnUnknownRoundAreNotFound(): void
    {
        $this->client->request('GET', '/rounds/019a6e2c-0000-7000-8000-000000000000/strokes');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAiFindsTheExactWordByItself(): void
    {
        $round = $this->startRound(['dog', 'Cats']);
        $this->addStroke($round, [[10, 10, 0], [500, 500, 300]]);

        $events = $this->streamGuesses($round);

        self::assertSame([
            ['guess', 'dog'],
            ['guess', 'Cats'],
            ['found', null],
        ], array_map(static fn (array $event): array => [$event['type'], $event['data']['word'] ?? null], $events));
        self::assertIsInt($events[2]['data']['foundAfterMs']);

        // Once found, the round shows the result and the stream only says so.
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('li.accepted', 'Cats');
        self::assertSame([['found', null]], array_map(static fn (array $event): array => [$event['type'], $event['data']['word'] ?? null], $this->streamGuesses($round)));
    }

    public function testTheAiDoesNotRepeatAWordAndWaitsForTheDrawingToChange(): void
    {
        $round = $this->startRound(['dog', 'Dogs', 'mouse', 'bird']);
        $this->addStroke($round, [[10, 10, 0]]);

        // Two guesses per unchanged drawing: "Dogs" repeats "dog" and is dropped, then the AI waits.
        $words = array_column(array_column($this->streamGuesses($round), 'data'), 'word');

        self::assertSame(['dog'], $words);
    }

    public function testTheAiSaysNothingOnAnEmptyCanvas(): void
    {
        $round = $this->startRound(['dog']);

        self::assertSame([], $this->streamGuesses($round));
    }

    public function testThePlayerAcceptsANearWord(): void
    {
        $round = $this->startRound(['kitten']);
        $this->addStroke($round, [[10, 10, 0]]);
        [$guess] = $this->streamGuesses($round);

        $this->client->request('POST', $round.'/guesses/'.$guess['data']['id'].'/accept');

        self::assertResponseIsSuccessful();
        self::assertIsInt($this->json()['foundAfterMs']);
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('li.accepted', 'kitten');
        self::assertSelectorNotExists('li button');
    }

    public function testAGuessOfAnotherRoundCannotBeAccepted(): void
    {
        $round = $this->startRound(['kitten']);
        $this->addStroke($round, [[10, 10, 0]]);
        [$guess] = $this->streamGuesses($round);
        $other = $this->startRound(scripted: false);

        $this->client->request('POST', $other.'/guesses/'.$guess['data']['id'].'/accept');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Starts a game whose word is "cat", and returns the URL of its round.
     *
     * @param list<string> $guesses
     * @param bool         $scripted False to keep the model of a round already started
     */
    private function startRound(array $guesses = [], bool $scripted = true): string
    {
        if ($scripted) {
            self::scriptModel($this->client, $guesses);
        }
        $this->client->request('POST', '/games', ['theme' => 'animals', 'language' => 'en']);
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * Reads the guesses stream until the server closes it.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function streamGuesses(string $round): array
    {
        ob_start();
        $this->client->request('GET', $round.'/guesses');
        $body = (string) ob_get_clean().$this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();

        $events = [];
        foreach (preg_split('/\n\n/', $body) ?: [] as $block) {
            if (preg_match('/^event: (\w+)$/m', $block, $type) && preg_match('/^data: (.*)$/m', $block, $data)) {
                $events[] = ['type' => $type[1], 'data' => json_decode($data[1], true, flags: \JSON_THROW_ON_ERROR)];
            }
        }

        return $events;
    }

    /**
     * @param list<array{int, int, int}> $points
     *
     * @return array<mixed>
     */
    private function addStroke(string $round, array $points): array
    {
        $this->postJson($round.'/strokes', ['points' => $points]);
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
