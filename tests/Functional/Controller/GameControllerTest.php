<?php

namespace App\Tests\Functional\Controller;

use App\Ai\ModelSettings;
use App\Ai\Provider;
use App\Ai\Role;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

final class GameControllerTest extends WebTestCase
{
    use ScriptedModel;

    public function testTheRootSpeaksTheLanguageOfTheBrowser(): void
    {
        $client = self::createClient();

        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);
        self::assertResponseRedirects('/fr');

        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja-JP']);
        self::assertResponseRedirects('/en');
    }

    public function testAnUnknownLanguageIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/ja');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRootSpeaksEachLanguageOfTheGame(): void
    {
        $client = self::createClient();

        foreach (['de', 'es', 'it'] as $locale) {
            $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
            self::assertResponseRedirects('/'.$locale);
        }
    }

    public function testTheLanguageSelectorIsAClosedDropDownOfFiveLanguages(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/es');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.languages > details:not([open])'));
        self::assertSame('Español', trim($crawler->filter('nav.languages summary')->text()));
        self::assertSame(
            ['Deutsch', 'English', 'Español', 'Français', 'Italiano'],
            (function () use ($crawler): array {
                $names = $crawler->filter('nav.languages a[hreflang]')->each(static fn ($a) => trim($a->text()));
                sort($names);

                return $names;
            })(),
        );
        self::assertSame('/es', $crawler->filter('nav.languages a[aria-current="page"]')->attr('href'));
        self::assertSame('it', $crawler->filter('nav.languages a[hreflang="it"]')->attr('lang'));
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

    public function testTheWordToDrawIsShownWithItsArticleAndTheAnswerIgnoresIt(): void
    {
        $client = self::createClient();
        // The player draws: the model lists a word with its article.
        self::scriptModel($client, word: 'un château fort', guesses: ['rien']);
        $round = self::startFirstRound($client, 'fr', ['drawnBy' => 'player']);

        $client->request('GET', $round);
        self::assertSelectorTextContains('.target strong', 'un château fort');
    }

    public function testTheHomePageSaysWhetherTheModelsCanPlay(): void
    {
        $client = self::createClient();
        // Both models name the circle: both read images.
        self::scriptModel($client, ['Circle', 'Circle']);

        $client->request('GET', '/fr');
        self::assertSelectorExists('.health[data-health-url-value="/fr/health"]');

        $client->request('GET', '/en/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['ok']);
        self::assertSame(['drawer', 'guesser'], array_column($report['roles'], 'role'));
    }

    public function testTheHealthCardIsOneLineWhenTheGuesserFollowsTheDrawer(): void
    {
        $client = self::createClient();
        self::scriptModel($client, ['circle', 'circle'], chosen: false);
        $client->getContainer()->get(ModelSettings::class)->choose(Role::Drawer, Provider::Anthropic, null, 'claude-haiku-5-5', false, 'sk-ant-api03-0123456789abcdef3f9a');

        $client->request('GET', '/fr/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertTrue($report['ok']);
        self::assertCount(1, $report['roles']);
        self::assertSame('both', $report['roles'][0]['role']);
        self::assertSame('claude-haiku-5-5 chez Anthropic', $report['roles'][0]['model']);
        $crawler = $client->request('GET', '/fr');
        self::assertStringContainsString('Il dessine et devine', $crawler->filter('.health')->attr('data-health-labels-value') ?? '');
    }

    public function testTheHealthCardHasALinePerRoleWhenEachHasItsOwnModel(): void
    {
        $client = self::createClient();
        self::scriptModel($client, ['circle', 'circle'], chosen: false);
        $settings = $client->getContainer()->get(ModelSettings::class);
        $settings->choose(Role::Drawer, Provider::Anthropic, null, 'claude-haiku-5-5', false, 'sk-ant-api03-0123456789abcdef3f9a');
        $settings->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen', false);

        $client->request('GET', '/en/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['drawer', 'guesser'], array_column($report['roles'], 'role'));
        self::assertSame(['claude-haiku-5-5 at Anthropic', 'qwen at http://gate:8001'], array_column($report['roles'], 'model'));
    }

    public function testTheHealthCardNamesTheAddressInTheLanguageOfThePage(): void
    {
        $client = self::createClient();
        self::scriptModel($client);

        $client->request('GET', '/fr/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($report['roles'] as $role) {
            self::assertStringNotContainsString(' at ', $role['model']);
            self::assertStringContainsString(' sur http', $role['model']);
        }
    }

    public function testAModelThatDoesNotSeeImagesIsReported(): void
    {
        $client = self::createClient();
        self::scriptModel($client, ['circle', 'square']);

        $client->request('GET', '/en/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertFalse($report['ok']);
        self::assertTrue($report['roles'][0]['ok']);
        self::assertFalse($report['roles'][1]['ok']);
        self::assertStringContainsString('does not recognize a circle', $report['roles'][1]['problem']);
        self::assertStringContainsString('(it answered “square”)', $report['roles'][1]['problem']);
    }

    public function testADrawerThatDoesNotSeeImagesIsReported(): void
    {
        $client = self::createClient();
        // A text-only drawer: it looks at its drawing to add details, so it must read images too.
        self::scriptModel($client, ['ready', 'circle']);

        $client->request('GET', '/en/health?fresh=1');
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

    public function testAModelThatNeverAnswersGivesAMessageAndARetryNotAnErrorPage(): void
    {
        $client = self::createClient();
        /** @var \ArrayObject<string, \Throwable> $outage */
        $outage = new \ArrayObject(['failure' => new TransportException('Max duration was reached for "http://model/v1/chat/completions".')]);
        self::scriptModel($client, outage: $outage);

        $client->request('POST', '/fr/games', []);
        $next = $client->getResponse()->headers->get('Location').'/rounds';
        $client->request('POST', $next);

        self::assertResponseStatusCodeSame(504);
        self::assertSelectorTextContains('.error', "Le modèle met trop de temps à répondre. S'il tourne sur ta machine, il est peut-être en train de se charger : réessaie dans un instant.");
        self::assertSame($next, $client->getCrawler()->filter('main form')->attr('action'), 'Try again repeats the action');
        self::assertSelectorExists('main form button[type=submit]');
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $client = self::createClient();
        self::scriptModel($client);

        $client->request('POST', '/en/games', ['mode' => 'cars']);

        self::assertResponseStatusCodeSame(422);
    }
}
