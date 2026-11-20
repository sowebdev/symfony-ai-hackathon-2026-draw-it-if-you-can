<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The AI of the game is a penguin: his name comes from GAME_AI_NAME, and is text like any other.
 */
final class AiNameTest extends WebTestCase
{
    use ScriptedModel;

    protected function tearDown(): void
    {
        unset($_SERVER['GAME_AI_NAME'], $_ENV['GAME_AI_NAME']);
        putenv('GAME_AI_NAME');
        parent::tearDown();
    }

    public function testOlafIsTheDefaultNameOnTheHomePageAndTheGuessScreen(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'girafe');

        $client->request('GET', '/fr');
        self::assertSelectorTextContains('h1 + p', 'Dessine pour Olaf');
        self::assertSelectorTextContains('.new-game', 'En équipe avec Olaf');

        self::assertStringContainsString('Que dessine Olaf ?', $this->guessScreen($client));
    }

    public function testAnEmptyNameFallsBackToOlaf(): void
    {
        self::setName('');
        $client = self::createClient();

        $client->request('GET', '/en');

        self::assertSelectorTextContains('h1 + p', "Draw for Olaf, or guess Olaf's drawings.");
    }

    public function testAnotherNameReplacesOlafEverywhere(): void
    {
        self::setName('Pingu');
        $client = self::createClient();
        self::scriptModel($client, word: 'girafe');

        $client->request('GET', '/fr');
        self::assertSelectorTextContains('h1 + p', 'Dessine pour Pingu');
        self::assertStringNotContainsString('Olaf', (string) $client->getResponse()->getContent());

        $page = $this->guessScreen($client);
        self::assertStringContainsString('Que dessine Pingu ?', $page);
        self::assertStringNotContainsString('Olaf', $page);
    }

    public function testTheNameIsEscaped(): void
    {
        self::setName('<b>Olaf</b>');
        $client = self::createClient();
        self::scriptModel($client, word: 'girafe');

        $client->request('GET', '/en');
        $home = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;Olaf&lt;/b&gt;', $home);
        self::assertStringNotContainsString('<b>Olaf</b>', $home);

        $page = $this->guessScreen($client);
        self::assertStringContainsString('&lt;b&gt;Olaf&lt;/b&gt;', $page);
        self::assertStringNotContainsString('<b>Olaf</b>', $page);
    }

    public function testHisHeadIsOnTheGuessScreenAndHisBeakMovesOnlyWhileHeDraws(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'girafe');

        $this->guessScreen($client);

        self::assertSelectorExists('.ai-voice svg.olaf.speaking .beak');
    }

    private static function setName(string $name): void
    {
        $_SERVER['GAME_AI_NAME'] = $_ENV['GAME_AI_NAME'] = $name;
        putenv('GAME_AI_NAME='.$name);
    }

    private function guessScreen(KernelBrowser $client): string
    {
        $round = self::startFirstRound($client, 'fr', ['drawnBy' => 'ai']);
        $client->request('GET', $round);

        return (string) $client->getResponse()->getContent();
    }
}
