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
        self::assertSelectorTextContains('option[value="animals"]', 'Animaux');

        $client->submitForm('Je dessine', ['theme' => 'animals']);
        self::assertResponseRedirects();

        // The round page speaks the language of its game, whatever the browser says.
        $client->request('GET', (string) $client->getResponse()->headers->get('Location'), server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('.target', 'À dessiner');
        self::assertSelectorTextContains('.target strong', 'girafe');
        self::assertSelectorTextContains('.round-number', 'Animaux');
        self::assertSelectorExists('canvas[data-canvas-target="canvas"]');
        self::assertSelectorNotExists('.copilot-badge', 'No copilot unless asked');
    }

    public function testTheHomePageSaysWhetherTheModelsCanPlay(): void
    {
        $client = self::createClient();
        // The drawer answers "ready", the guesser names the circle.
        self::scriptModel($client, ['ready', 'Circle']);

        $client->request('GET', '/fr');
        self::assertSelectorExists('.health[data-health-url-value="/health"]');
        self::assertSelectorExists('form.new-game[data-action="loading#show"]');

        $client->request('GET', '/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['ok']);
        self::assertSame(['drawer', 'guesser'], array_column($report['roles'], 'role'));
    }

    public function testAModelThatDoesNotSeeImagesIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, ['ready', 'square']);

        $client->request('GET', '/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertFalse($report['ok']);
        self::assertTrue($report['roles'][0]['ok']);
        self::assertFalse($report['roles'][1]['ok']);
        self::assertStringContainsString('does not recognize a circle', $report['roles'][1]['problem']);
    }

    public function testAModelWithoutAUsableWordIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'I would say a giraffe');

        $client->request('POST', '/en/games', ['theme' => 'animals']);

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('.error', 'could not pick a word');
    }

    public function testAnUnknownThemeIsRejected(): void
    {
        $client = self::createClient();
        self::scriptModel($client);

        $client->request('POST', '/en/games', ['theme' => 'cars']);

        self::assertResponseStatusCodeSame(422);
    }
}
