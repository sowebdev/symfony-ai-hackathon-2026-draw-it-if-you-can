<?php

namespace App\Tests\Functional\Controller;

use App\Entity\JudgedPair;
use App\Game\GameSettings;
use App\Game\Settings;
use App\Game\Theme;
use App\Game\Turns;
use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * The judge: every answer gets accepted, warm or refused, from the label of the model and the mode of the game.
 */
final class JudgeTest extends WebTestCase
{
    use EventStream;
    use ScriptedModel;

    private KernelBrowser $client;

    /** @var \ArrayObject<string, mixed> */
    private \ArrayObject $judgement;

    /** @var \ArrayObject<int, mixed> */
    private \ArrayObject $calls;

    private bool $scripted = false;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->judgement = new \ArrayObject(['label' => 'unrelated']);
        $this->calls = new \ArrayObject();
    }

    public function testAWarmAnswerIsToldWarmAndTheRoundGoesOn(): void
    {
        $this->judgement['label'] = 'synonym';
        $round = $this->startRound('ai');

        $this->answer($round, 'long cou');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('warm', $this->json()['verdict']);
        self::assertFalse($this->json()['found']);

        $this->answer($round, 'Girafe');
        self::assertSame('accepted', $this->json()['verdict']);
        self::assertTrue($this->json()['found']);

        $this->client->request('GET', $round);
        self::assertSelectorExists('li.accepted');
        self::assertSelectorTextContains('li.warm', 'long cou');
    }

    public function testTheSameAnswerInEasyModeIsAccepted(): void
    {
        $this->judgement['label'] = 'synonym';
        $round = $this->startRound('ai', easy: true);

        $this->answer($round, 'long cou');

        self::assertSame('accepted', $this->json()['verdict']);
        self::assertTrue($this->json()['found']);
    }

    public function testARelatedAnswerIsWarmOnlyInEasyMode(): void
    {
        $this->judgement['label'] = 'related';
        $normal = $this->startRound('ai');
        $this->answer($normal, 'savane');
        self::assertSame('refused', $this->json()['verdict']);

        $easy = $this->startRound('ai', easy: true);
        $this->answer($easy, 'savane');
        self::assertSame('warm', $this->json()['verdict']);
        self::assertFalse($this->json()['found']);
    }

    public function testAGameKeepsTheModeItStartedWith(): void
    {
        $this->judgement['label'] = 'synonym';
        $round = $this->startRound('ai', easy: true);
        $this->saveSettings(false);

        $this->answer($round, 'long cou');

        self::assertSame('accepted', $this->json()['verdict']);
    }

    public function testTheCodeAcceptsAWordAndOneTypoWithoutAskingTheModel(): void
    {
        $round = $this->startRound('ai');

        $this->answer($round, 'girafee');

        self::assertSame('accepted', $this->json()['verdict']);
        self::assertSame(0, $this->judgeCalls(), 'The model is not asked');
    }

    public function testAJudgedPairIsNeverAskedAgainEvenInAnotherGame(): void
    {
        $this->judgement['label'] = 'synonym';
        $first = $this->startRound('ai');
        $this->answer($first, 'long cou');
        self::assertSame(1, $this->judgeCalls());

        $second = self::startFirstRound($this->client, 'fr', ['drawnBy' => 'ai']);
        $this->answer($second, 'Long  cou');

        self::assertSame('warm', $this->json()['verdict']);
        self::assertSame(1, $this->judgeCalls(), 'The second game does not call the model');
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(JudgedPair::class)->count([]));

        // Another language is another pair.
        $english = self::startFirstRound($this->client, 'en', ['drawnBy' => 'ai']);
        $this->answer($english, 'long cou');
        self::assertSame(2, $this->judgeCalls());
    }

    public function testAJudgeThatFailsRefusesTheAnswerAndTheRoundGoesOn(): void
    {
        $this->judgement['label'] = new TransportException('Max duration was reached');
        $round = $this->startRound('ai');

        $this->answer($round, 'long cou');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('refused', $this->json()['verdict']);
        self::assertFalse($this->json()['found']);

        // Nothing is kept of a failure: the next time, the judge is asked again.
        $this->judgement['label'] = 'synonym';
        $this->answer($round, 'long cou');
        self::assertSame('warm', $this->json()['verdict']);

        $this->judgement['label'] = 'I do not know';
        $this->answer($round, 'cou');
        self::assertSame('refused', $this->json()['verdict'], 'An unreadable label is no label');
        $this->judgement['label'] = 'same_word';
        $this->answer($round, 'cou');
        self::assertSame('accepted', $this->json()['verdict'], 'And it was not kept');
    }

    public function testOlafGuessesAndTheEventCarriesTheVerdict(): void
    {
        $this->judgement['label'] = 'very_close';
        $round = $this->startRound('player', ['lion']);
        $this->postJson($round.'/strokes', ['points' => [[10, 10, 0], [500, 500, 300]]]);

        $events = self::readEvents($this->client, $round.'/guesses');

        self::assertSame('guess', $events[0]['type']);
        self::assertSame(['word' => 'lion', 'verdict' => 'warm'], ['word' => $events[0]['data']['word'], 'verdict' => $events[0]['data']['verdict']]);
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('li.warm', 'lion');
    }

    public function testOlafFindsTheWordInEasyModeBySynonym(): void
    {
        $this->judgement['label'] = 'synonym';
        $round = $this->startRound('player', ['long cou'], easy: true);
        $this->postJson($round.'/strokes', ['points' => [[10, 10, 0], [500, 500, 300]]]);

        $events = self::readEvents($this->client, $round.'/guesses');

        self::assertSame(['guess', 'found'], array_column($events, 'type'));
        self::assertSame('accepted', $events[0]['data']['verdict']);
    }

    /**
     * @param list<string> $guesses
     */
    private function startRound(string $drawnBy, array $guesses = [], bool $easy = false): string
    {
        if (!$this->scripted) {
            self::scriptModel($this->client, $guesses, word: 'girafe', strokes: [['neck', 'x25y45,x25y10']], judgement: $this->judgement, calls: $this->calls);
            $this->scripted = true;
        }
        $this->saveSettings($easy);

        return self::startFirstRound($this->client, 'fr', ['drawnBy' => $drawnBy]);
    }

    private function judgeCalls(): int
    {
        return \count(array_filter($this->calls->getArrayCopy(), static fn (mixed $input): bool => str_contains((string) json_encode($input), 'which link there is')));
    }

    private function saveSettings(bool $easy): void
    {
        self::getContainer()->get(GameSettings::class)->save(new Settings(Theme::cases(), Turns::Alternate, 6, 60, $easy));
    }

    private function answer(string $round, string $word): void
    {
        $this->postJson($round.'/answers', ['word' => $word]);
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
