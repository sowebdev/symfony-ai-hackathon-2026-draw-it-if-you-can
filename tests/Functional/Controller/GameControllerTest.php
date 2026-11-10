<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameControllerTest extends WebTestCase
{
    use ScriptedModel;

    public function testANewGameStartsARoundWithTheWordOfTheAi(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'giraffe');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->submitForm('New game', ['theme' => 'animals', 'language' => 'fr']);
        self::assertResponseRedirects();

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.target strong', 'giraffe');
        self::assertSelectorExists('canvas[data-canvas-target="canvas"]');
    }

    public function testAModelWithoutAUsableWordIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, word: 'I would say a giraffe');

        $client->request('POST', '/games', ['theme' => 'animals', 'language' => 'en']);

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('.error', 'could not pick a word');
    }

    public function testAnUnknownThemeIsRejected(): void
    {
        $client = self::createClient();
        self::scriptModel($client);

        $client->request('POST', '/games', ['theme' => 'cars', 'language' => 'en']);

        self::assertResponseStatusCodeSame(422);
    }
}
