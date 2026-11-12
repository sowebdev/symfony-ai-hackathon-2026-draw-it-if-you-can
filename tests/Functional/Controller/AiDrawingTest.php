<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedDrawer;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AiDrawingTest extends WebTestCase
{
    use EventStream;
    use ScriptedModel;

    private const array STROKES = [['neck', 'x25y45,x25y10'], ['head', 'x25y10,x30y8,x30y8,x32y12'], ['not a stroke', 'somewhere']];

    private KernelBrowser $client;
    private ScriptedDrawer $drawer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->drawer = self::scriptModel($this->client, word: 'girafe', strokes: self::STROKES);
    }

    public function testTheWordIsNotShownToThePlayer(): void
    {
        $this->client->request('GET', '/fr');
        $this->client->submitForm("L'IA dessine", ['theme' => 'animals']);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.target', "Que dessine l'IA ?");
        self::assertStringNotContainsString('girafe', (string) $this->client->getResponse()->getContent());
    }

    public function testTheAiDrawsStrokeByStrokeAndARequestThatComesLaterReplaysThem(): void
    {
        $round = $this->startRound();

        $events = self::readEvents($this->client, $round.'/drawing');

        self::assertSame(['stroke', 'stroke', 'finished'], array_column($events, 'type'));
        self::assertSame([['girafe', 'animals']], $this->drawer->drawn, 'The drawer gets the word and the theme');
        self::assertSame([1, 2], array_column(array_slice(array_column($events, 'data'), 0, 2), 'position'));
        self::assertTrue($events[0]['data']['animate']);
        self::assertGreaterThan(10, \count($events[0]['data']['points']), 'Turned into a hand-drawn line');

        // A reload: the stored strokes come back at once, and the model is not called again.
        $replay = self::readEvents($this->client, $round.'/drawing');
        self::assertSame(['stroke', 'stroke', 'finished'], array_column($replay, 'type'));
        self::assertFalse($replay[0]['data']['animate']);
        self::assertSame($events[0]['data']['points'], $replay[0]['data']['points']);
        self::assertCount(1, $this->drawer->drawn);
    }

    public function testThePlayerGuessesAndTheCodeJudges(): void
    {
        $round = $this->startRound();

        $this->answer($round, 'cheval');
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->json()['found']);
        self::assertNull($this->json()['targetWord']);

        $this->answer($round, 'Girafes');
        self::assertTrue($this->json()['found']);
        self::assertIsInt($this->json()['foundAfterMs']);

        $this->answer($round, 'girafe');
        self::assertResponseStatusCodeSame(409);

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('li.accepted', 'Girafes');
    }

    public function testGivingUpRevealsTheWordAndStopsTheDrawing(): void
    {
        $round = $this->startRound();

        $this->client->request('POST', $round.'/give-up');
        self::assertSame('girafe', $this->json()['targetWord']);

        self::assertSame(['finished'], array_column(self::readEvents($this->client, $round.'/drawing'), 'type'));
        $this->answer($round, 'girafe');
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-end-word strong', 'girafe');
    }

    public function testThePlayerCannotDrawInARoundOfTheAi(): void
    {
        $round = $this->startRound();

        $this->client->request('POST', $round.'/strokes', server: ['CONTENT_TYPE' => 'application/json'], content: '{"points":[[1,1,0]]}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $round.'/guesses');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAiDoesNotDrawInARoundOfThePlayer(): void
    {
        $this->client->request('POST', '/en/games', ['theme' => 'animals', 'drawnBy' => 'player']);
        $round = (string) $this->client->getResponse()->headers->get('Location');

        $this->client->request('GET', $round.'/drawing');
        self::assertResponseStatusCodeSame(404);
        $this->answer($round, 'girafe');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAModelCutShortKeepsItsStrokes(): void
    {
        $this->restartWith(self::STROKES, new \RuntimeException('Token limit'));

        self::assertSame(['stroke', 'stroke', 'finished'], array_column(self::readEvents($this->client, $this->startRound().'/drawing'), 'type'));
    }

    public function testAModelThatDrawsNothingHasFailed(): void
    {
        $this->restartWith([], new \RuntimeException('Model not loaded'));

        self::assertSame(['failed', 'finished'], array_column(self::readEvents($this->client, $this->startRound().'/drawing'), 'type'));
    }

    /**
     * @param list<array{string, string}> $strokes
     */
    private function restartWith(array $strokes, \Throwable $failure): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->drawer = self::scriptModel($this->client, word: 'girafe', strokes: $strokes, drawingFailure: $failure);
    }

    private function startRound(): string
    {
        $this->client->request('POST', '/fr/games', ['theme' => 'animals', 'drawnBy' => 'ai']);
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function answer(string $round, string $word): void
    {
        $this->client->request('POST', $round.'/answers', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['word' => $word], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
