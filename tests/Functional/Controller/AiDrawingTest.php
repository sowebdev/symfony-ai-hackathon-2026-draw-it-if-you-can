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
        $this->client->submitForm('Commencer la partie');
        $this->client->followRedirect();
        // The page of the card of the first theme: without JavaScript, a button starts the round.
        $this->client->submitForm('Commencer la partie');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.target', 'Que dessine Olaf ?');
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
        self::assertSame(1, $this->drawer->timesAskedForASketch());
    }

    public function testTheAiAddsDetailsUntilTheEndOfTheRound(): void
    {
        $this->restartWith(self::STROKES, details: [['tail', 'x40y40,x45y45']]);
        $round = $this->startRound();
        $this->answer($round, 'cheval');

        $events = self::readEvents($this->client, $round.'/drawing');

        // Nobody finds the word: the AI adds a stroke at each pass, up to the technical guard.
        self::assertSame([...array_fill(0, 9, 'stroke'), 'finished'], array_column($events, 'type'));
        self::assertCount(1, $this->drawer->drawn, 'One first sketch');
        self::assertSame([2, ['cheval']], $this->drawer->detailed[0], 'Details are asked with the strokes drawn and the wrong answers');
        self::assertSame([8, ['cheval']], $this->drawer->detailed[6]);
    }

    public function testTheAiStopsWhenItHasNothingToAdd(): void
    {
        $round = $this->startRound();

        self::assertSame(['stroke', 'stroke', 'finished'], array_column(self::readEvents($this->client, $round.'/drawing'), 'type'));
        self::assertCount(2, $this->drawer->detailed, 'Two passes without a stroke, then the drawing is finished');
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

    public function testTheWalrusRefereeIsAtRestThenOnTheRightAnswerAfterAReload(): void
    {
        $round = $this->startRound();

        $this->client->request('GET', $round);
        self::assertSelectorExists('section.guesses .referee');
        self::assertSelectorNotExists('.referee.is-accepted');
        self::assertSelectorExists('.referee-accepted[hidden]');

        $this->answer($round, 'girafe');
        $this->client->request('GET', $round);
        self::assertSelectorExists('.referee.is-accepted');
        self::assertSelectorNotExists('.referee-accepted[hidden]');
        self::assertSelectorTextContains('.referee-accepted', "C'est juste");
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
        $this->client->request('POST', '/en/games', ['drawnBy' => 'player']);
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
     * @param list<array{string, string}> $details
     */
    private function restartWith(array $strokes, ?\Throwable $failure = null, array $details = []): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->drawer = self::scriptModel($this->client, word: 'girafe', strokes: $strokes, drawingFailure: $failure, details: $details);
    }

    private function startRound(): string
    {
        return self::startFirstRound($this->client, 'fr', ['drawnBy' => 'ai']);
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
