<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameControllerTest extends WebTestCase
{
    use ScriptedModel;

    public function testTheRootSpeaksTheLanguageOfTheBrowser(): void
    {
        $client = self::createClient();

        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);
        self::assertResponseRedirects('/fr');

        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
        self::assertResponseRedirects('/en');
    }

    public function testAnUnknownLanguageIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGameIsPlayedInTheLanguageOfThePage(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'girafe');

        $client->request('GET', '/fr');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('html[lang="fr"] h1 + p', "Dessine pour l'IA, ou devine ses dessins.");
        self::assertSelectorTextContains('.who-starts', 'tirés au hasard');

        $client->request('POST', '/fr/games', ['drawnBy' => 'player']);
        self::assertResponseRedirects();
        $game = (string) $client->getResponse()->headers->get('Location');

        // The card of the first theme turns over, then the form starts the round by itself.
        $client->request('GET', $game, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-loading-auto-value="true"][data-loading-theme-value="Animaux"][data-loading-color-value="theme-animals"][data-loading-round-value="Manche 1 / 6"]');
        $client->request('POST', $game.'/rounds');

        // The round page speaks the language of its game, whatever the browser says.
        $client->request('GET', (string) $client->getResponse()->headers->get('Location'), server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('.target', 'À dessiner');
        self::assertSelectorTextContains('.target strong', 'girafe');
        self::assertSelectorTextContains('.theme-tag', 'Animaux');
        self::assertSelectorExists('canvas[data-canvas-target="canvas"]');
        self::assertSelectorNotExists('.copilot-badge', 'No copilot unless asked');
    }

    public function testTheHomePageSaysWhetherTheModelsCanPlay(): void
    {
        $client = self::createClient();
        // Both models name the circle: both read images.
        self::scriptModel($client, ['Circle', 'Circle']);

        $client->request('GET', '/fr');
        self::assertSelectorExists('.health[data-health-url-value="/health"]');

        $client->request('GET', '/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['ok']);
        self::assertSame(['drawer', 'guesser'], array_column($report['roles'], 'role'));
    }

    public function testAModelThatDoesNotSeeImagesIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, ['circle', 'square']);

        $client->request('GET', '/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertFalse($report['ok']);
        self::assertTrue($report['roles'][0]['ok']);
        self::assertFalse($report['roles'][1]['ok']);
        self::assertStringContainsString('does not recognize a circle', $report['roles'][1]['problem']);
    }

    public function testADrawerThatDoesNotSeeImagesIsReported(): void
    {
        $client = self::createClient();
        // A text-only drawer: it looks at its drawing to add details, so it must read images too.
        self::scriptModel($client, ['ready', 'circle']);

        $client->request('GET', '/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertFalse($report['ok']);
        self::assertSame('drawer', $report['roles'][0]['role']);
        self::assertStringContainsString('must be a model that reads images', $report['roles'][0]['problem']);
        self::assertTrue($report['roles'][1]['ok']);
    }

    public function testAModelWithoutAUsableWordIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'I would say a giraffe');

        $client->request('POST', '/en/games', []);
        $client->request('POST', $client->getResponse()->headers->get('Location').'/rounds');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('.error', 'could not pick a word');
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $client = self::createClient();
        self::scriptModel($client);

        $client->request('POST', '/en/games', ['mode' => 'cars']);

        self::assertResponseStatusCodeSame(422);
    }
}
