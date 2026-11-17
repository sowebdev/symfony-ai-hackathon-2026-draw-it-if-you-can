<?php

namespace App\Tests\Functional\Mcp;

use App\Tests\Functional\EventStream;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Which game the agent plays when it names no round: the one whose page the player opened last.
 */
final class FollowedGameTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EventStream;
    use McpClient;
    use ScriptedModel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client, guesses: ['cheval'], word: 'girafe', strokes: [['neck', 'x25y45,x25y10']]);
        self::mockTime();
        $this->connect();
    }

    public function testTheAgentPlaysTheGameWhosePageThePlayerOpenedLast(): void
    {
        $older = $this->startRound('ai');
        Clock::get()->sleep(2);
        $newer = $this->startRound('player');
        self::assertSame('player', $this->roundStatus()['drawn_by'], 'The game created last, nothing opened since');

        Clock::get()->sleep(2);
        $this->client->request('GET', $older);
        self::assertResponseIsSuccessful();
        self::assertSame('ai', $this->roundStatus()['drawn_by'], 'The page of the older game was opened last');

        Clock::get()->sleep(2);
        $this->client->request('GET', $newer);
        self::assertSame('player', $this->roundStatus()['drawn_by']);
    }

    public function testThePollingAndTheEventStreamsDoNotChangeTheGameFollowed(): void
    {
        $older = $this->startRound('ai');
        Clock::get()->sleep(2);
        $newer = $this->startRound('player');
        Clock::get()->sleep(2);
        $this->client->request('GET', $older);
        Clock::get()->sleep(2);

        // An old tab on the newer game keeps polling.
        $this->client->request('GET', $newer.'/strokes');
        self::readEvents($this->client, $newer.'/guesses');
        self::assertSame('ai', $this->roundStatus()['drawn_by']);
    }

    public function testAGameLeftByThePlayerIsNeverFollowed(): void
    {
        $this->startRound('player');
        Clock::get()->sleep(2);
        $left = $this->startRound('ai');
        Clock::get()->sleep(2);
        $this->client->request('GET', $left);
        $this->client->submitForm('Quitter la partie');

        $status = $this->roundStatus();
        self::assertSame('player', $status['drawn_by'], 'The game left, though opened last, is not followed');
        self::assertFalse($status['stop']);
    }

    public function testASoloGameOpenedLastMakesTheAgentStop(): void
    {
        $round = $this->startRound('ai');
        Clock::get()->sleep(2);
        $this->client->request('POST', '/fr/games', ['drawnBy' => 'player']);
        $solo = (string) $this->client->getResponse()->headers->get('Location');
        $status = $this->roundStatus();
        self::assertTrue($status['stop'], 'The solo game was created last');

        Clock::get()->sleep(2);
        $this->client->request('GET', $round);
        $status = $this->roundStatus();
        self::assertFalse($status['stop'], 'The player is back on the game with the agent');

        Clock::get()->sleep(2);
        $this->client->request('GET', $solo);
        $status = $this->roundStatus();
        self::assertTrue($status['stop']);
        self::assertStringContainsString('alone', $status['what_you_can_do']);
    }
}
