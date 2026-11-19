<?php

namespace App\Tests\Functional\Controller;

use App\Ai\Encryption;
use App\Ai\ModelLister;
use App\Ai\ModelSettings;
use App\Ai\Provider;
use App\Ai\Role;
use App\Tests\Functional\ScriptedModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The model screen, step by step, on recorded lists (MockHttpClient) and the fake model: no provider is called.
 */
final class ModelsControllerTest extends WebTestCase
{
    use ScriptedModel;

    private const string KEY = 'sk-ant-api03-0123456789abcdef3f9a';

    /** @var list<string> Everything the server sent back, to look for the key in */
    private array $bodies = [];

    /** @var list<string> The addresses the game asked for models at */
    private array $asked = [];

    /**
     * @param array<string, mixed>|null     $list    What GET /v1/models answers; null: nothing answers
     * @param list<string>                  $guesses What the fake model answers when asked what shape it sees
     * @param bool                          $chosen  Whether a model is stored already (by default none, as on a fresh install)
     * @param \ArrayObject<int, mixed>|null $calls   Gets the input of each call to the fake model
     */
    private function client(?array $list, array $guesses = ['circle', 'circle'], bool $chosen = false, ?\ArrayObject $calls = null): KernelBrowser
    {
        $client = self::createClient();
        self::scriptModel($client, $guesses, chosen: $chosen, calls: $calls);
        $http = new MockHttpClient(function (string $method, string $url) use ($list): MockResponse {
            $this->asked[] = $url;
            if (null === $list) {
                throw new TransportException('Failed to connect');
            }

            return new MockResponse(json_encode($list, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $client->getContainer()->set(ModelLister::class, new ModelLister($http, new ArrayAdapter()));

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function anthropicList(): array
    {
        return ['data' => [['id' => 'claude-sonnet-5-5'], ['id' => 'claude-haiku-5-5'], ['id' => 'claude-brand-new-9'], ['id' => 'claude-embeddings-or-so']], 'has_more' => false];
    }

    private function get(KernelBrowser $client, string $uri): Crawler
    {
        $crawler = $client->request('GET', $uri);
        $this->bodies[] = (string) $client->getResponse()->getContent();

        return $crawler;
    }

    private function step1(KernelBrowser $client, string $provider = 'anthropic', string $key = self::KEY, ?string $address = null): void
    {
        $crawler = $this->get($client, '/en/models');
        $values = ['provider_step[provider]' => $provider, 'provider_step[apiKey]' => $key];
        if (null !== $address) {
            $values['provider_step[address]'] = $address;
        }
        $client->submit($crawler->selectButton('List the models')->form($values));
        $this->bodies[] = (string) $client->getResponse()->getContent();
    }

    private static function selected(Crawler $crawler, string $field): mixed
    {
        return $crawler->selectButton('Check the models')->form()->getValues()[$field] ?? null;
    }

    private function noKeyInAnyPage(string $key = self::KEY): void
    {
        foreach ($this->bodies as $body) {
            self::assertStringNotContainsString($key, $body, 'The API key never goes back to the browser');
        }
    }

    public function testStepOneOffersTheFourProvidersAndNeverTheKey(): void
    {
        $client = $this->client(self::anthropicList());

        $crawler = $this->get($client, '/en/models');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choose the model');
        self::assertCount(4, $crawler->filter('.provider-cards input[type=radio]'));
        self::assertSame(['anthropic', 'openai', 'mistral', 'openai_compatible'], $crawler->filter('.provider-cards input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSelectorTextContains('.provider-cards', 'Another OpenAI-compatible service (Ollama, LM Studio, OpenRouter…)');
        self::assertCount(1, $crawler->filter('input[type=password]'));
        self::assertSame('', $crawler->filter('input[type=password]')->attr('value') ?? '');
        self::assertSame('', $crawler->filter('input[name="provider_step[address]"]')->attr('value') ?? '', 'The address starts empty: the game names no default server');
        self::assertSame('http://host.docker.internal:PORT', $crawler->filter('input[name="provider_step[address]"]')->attr('placeholder'));
        self::assertSame('anthropic', $crawler->filter('.provider-cards input:checked')->attr('value'));
        self::assertSelectorExists('button:contains("List the models")');
        // The only attribute of Stimulus is the one of the CSRF token of the form, whose fallback needs no script here (the browser's own headers do it).
        self::assertSame(['csrf-protection'], $crawler->filter('main.models [data-controller]')->each(static fn (Crawler $node): string => (string) $node->attr('data-controller')));
        self::assertCount(0, $crawler->filter('main.models script'));
    }

    public function testTheThreeStepsSaveTheSettingAndGoHome(): void
    {
        $client = $this->client(self::anthropicList());

        $this->step1($client);
        self::assertResponseRedirects('/en/models/choose');

        // Step 2: only what the catalog says can play, sorted, nothing preselected, a field to type a name.
        $crawler = $this->get($client, '/en/models/choose');
        self::assertResponseIsSuccessful();
        $options = static fn (string $role): array => $crawler->filter(\sprintf('select[name="models_step[%sChoice]"] option', $role))->each(static fn (Crawler $option): string => trim($option->text()));
        self::assertSame(['— choose a model —', 'claude-haiku-5-5 — reads images, calls tools', 'claude-sonnet-5-5 — reads images, calls tools'], $options('guesser'));
        self::assertSame($options('guesser'), $options('drawer'));
        self::assertCount(0, $crawler->filter('select option[selected]'), 'No model is preselected');
        self::assertCount(1, $crawler->filter('input[name="models_step[guesserName]"]'));
        self::assertCount(1, $crawler->filter('input[name="models_step[drawerName]"]'));
        self::assertSelectorNotExists('input[type=checkbox]', 'Anthropic: the game decides about reasoning, no switch');
        self::assertStringNotContainsStringIgnoringCase('tested', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsStringIgnoringCase('suggested', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/en/models?back=1"]');

        $client->submit($crawler->selectButton('Check the models')->form([
            'models_step[guesserChoice]' => 'claude-haiku-5-5',
            'models_step[drawerName]' => 'claude-brand-new-9',
        ]));
        self::assertResponseRedirects('/en/models/check');
        self::assertFalse(self::getContainer()->get(ModelSettings::class)->isStored(), 'Nothing is saved before the check passes');

        // Step 3: both pass, the typed name too, then "Save and play".
        $crawler = $this->get($client, '/en/models/check');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.check-results li.ok'));
        self::assertSelectorTextContains('.check-results', 'checked, on this machine');
        self::assertSelectorTextContains('.check-results', 'claude-brand-new-9');
        self::assertFalse(self::getContainer()->get(ModelSettings::class)->isStored());
        $client->submit($crawler->selectButton('Save and play')->form());
        self::assertResponseRedirects('/en');

        $settings = self::getContainer()->get(ModelSettings::class);
        $view = $settings->view();
        self::assertSame(Provider::Anthropic, $view?->provider);
        self::assertSame('claude-haiku-5-5', $view->guesserModel);
        self::assertSame('claude-brand-new-9', $view->drawerModel);
        self::assertSame('…3f9a', $view->maskedApiKey);
        self::assertSame(self::KEY, $settings->forRole(Role::Drawer)->apiKey());
        $row = (string) json_encode(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM ai_setting'));
        self::assertStringNotContainsString(self::KEY, $row, 'The key is sealed in the database');

        $this->get($client, '/en');
        $this->noKeyInAnyPage();
        // The draft, and the key in it, is dropped once saved: going back to step 2 starts again.
        $client->request('GET', '/en/models/choose');
        self::assertResponseRedirects('/en/models');
    }

    public function testTheHomePageDoesNotCheckAgainWhatStepThreeJustChecked(): void
    {
        $calls = new \ArrayObject();
        $client = $this->client(self::anthropicList(), calls: $calls);
        $this->step1($client);
        $crawler = $this->get($client, '/en/models/choose');
        $client->submit($crawler->selectButton('Check the models')->form([
            'models_step[guesserChoice]' => 'claude-haiku-5-5',
            'models_step[drawerChoice]' => 'claude-sonnet-5-5',
        ]));
        $crawler = $this->get($client, '/en/models/check');
        self::assertCount(2, $crawler->filter('.check-results li.ok'));
        $callsOfStepThree = \count($calls);
        self::assertGreaterThan(0, $callsOfStepThree);
        $client->submit($crawler->selectButton('Save and play')->form());
        self::assertResponseRedirects('/en');

        $client->request('GET', '/en');
        $client->request('GET', '/en/health');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertTrue($report['ok'], 'The home page shows the result of step 3');
        self::assertCount($callsOfStepThree, $calls, 'The models are not called again');
    }

    public function testTheKeyIsInTheSessionSealedAndNeverInAResponse(): void
    {
        $client = $this->client(self::anthropicList());

        $this->step1($client);
        $this->get($client, '/en/models/choose');

        $session = $client->getRequest()->getSession()->all();
        self::assertStringNotContainsString(self::KEY, json_encode($session, \JSON_THROW_ON_ERROR), 'Between the steps the key is sealed');
        self::assertNotSame('', $session['model_draft']['key'] ?? '');
        $this->noKeyInAnyPage();
        self::assertStringNotContainsString(self::KEY, implode("\n", array_map(static fn (string $uri): string => $uri, $this->asked)));

        // Abandoned: on the home page, the draft is dropped.
        $this->get($client, '/en');
        self::assertArrayNotHasKey('model_draft', $client->getRequest()->getSession()->all());
    }

    public function testAFailingCheckSavesNothingAndTheChoicesStayFilled(): void
    {
        // The guesser sees a circle, the drawer does not: it answers "square".
        $client = $this->client(self::anthropicList(), ['circle', 'square']);

        $this->step1($client);
        $crawler = $this->get($client, '/en/models/choose');
        $client->submit($crawler->selectButton('Check the models')->form(['models_step[guesserChoice]' => 'claude-haiku-5-5', 'models_step[drawerChoice]' => 'claude-sonnet-5-5']));
        $crawler = $this->get($client, '/en/models/check');

        self::assertCount(1, $crawler->filter('.check-results li.ok'));
        self::assertCount(1, $crawler->filter('.check-results li.failed'));
        self::assertSelectorTextContains('.check-results li.failed', 'does not recognize a circle');
        self::assertSelectorNotExists('button:contains("Save and play")');
        self::assertSelectorTextContains('.models', 'Nothing was saved');
        self::assertFalse(self::getContainer()->get(ModelSettings::class)->isStored());

        // A save posted by hand does not save either: the draft did not pass its check.
        $client->request('POST', '/en/models/save');
        self::assertResponseRedirects('/en/models/check');
        self::assertFalse(self::getContainer()->get(ModelSettings::class)->isStored());

        // Back to step 2: what was chosen is still there.
        $crawler = $this->get($client, '/en/models/choose');
        self::assertSame('claude-haiku-5-5', self::selected($crawler, 'models_step[guesserChoice]'));
        self::assertSame('claude-sonnet-5-5', self::selected($crawler, 'models_step[drawerChoice]'));
        $this->noKeyInAnyPage();
    }

    public function testAUnreadableListSaysWhyAndLeavesTheTextFields(): void
    {
        $client = $this->client(null);

        $this->step1($client, 'openai_compatible', '', 'http://gate:8001');
        $crawler = $this->get($client, '/en/models/choose');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.models-problem', 'The list of models cannot be read.');
        self::assertSelectorTextContains('.models-problem', 'Nothing answers at http://gate:8001');
        self::assertCount(0, $crawler->filter('select'));
        self::assertCount(2, $crawler->filter('input[type=text]'));

        $client->submit($crawler->selectButton('Check the models')->form(['models_step[guesserName]' => 'qwen', 'models_step[drawerName]' => ' qwen ']));
        self::assertResponseRedirects('/en/models/check');
    }

    public function testAModelMustBeChosenOrTyped(): void
    {
        $client = $this->client(self::anthropicList());
        $this->step1($client);

        $crawler = $this->get($client, '/en/models/choose');
        $client->submit($crawler->selectButton('Check the models')->form(['models_step[guesserChoice]' => 'claude-haiku-5-5']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'Choose a model in the list, or type its name.');
    }

    public function testAnotherServerShowsTheReasoningSwitchOnOnlyForALocalAddress(): void
    {
        foreach (['http://host.docker.internal:11434' => true, 'http://localhost:1234' => true, 'http://192.168.1.20:8080' => true, 'https://openrouter.ai/api' => false] as $address => $on) {
            self::ensureKernelShutdown();
            $client = $this->client(['data' => [['id' => 'qwen3.8:27b']]]);
            $this->step1($client, 'openai_compatible', 'sk-or', $address);
            $crawler = $this->get($client, '/en/models/choose');

            self::assertSelectorTextContains('.switch-label', 'Turn reasoning off, for a local model that thinks for too long');
            self::assertSame($on, null !== $crawler->filter('input[name="models_step[reasoningOff]"]')->attr('checked'), $address);
        }
    }

    public function testOnlyTheOtherServerHasTheSwitchAndTheModelsOfAListWithoutCapabilitiesSaySo(): void
    {
        $client = $this->client(['data' => [['id' => 'zeta'], ['id' => 'alpha']]]);
        $this->step1($client, 'openai_compatible', '', 'http://gate:8001');

        $crawler = $this->get($client, '/en/models/choose');

        self::assertSame(['— choose a model —', 'alpha', 'zeta'], $crawler->filter('select[name="models_step[guesserChoice]"] option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSelectorTextContains('.role-models', 'capabilities unknown, the check will tell');
    }

    public function testAHugeListIsFoldedAndTheTextFieldComesFirst(): void
    {
        $entries = array_map(static fn (int $i): array => ['id' => \sprintf('vendor/model-%03d', $i)], range(1, 300));
        $client = $this->client(['data' => $entries]);
        $this->step1($client, 'openai_compatible', 'sk-or', 'https://openrouter.ai/api');

        $crawler = $this->get($client, '/en/models/choose');

        self::assertCount(2, $crawler->filter('details.long-list'));
        self::assertSelectorTextContains('details.long-list summary', 'Pick from the 300 models');
        self::assertCount(0, $crawler->filter('details.long-list[open]'));
        self::assertCount(301, $crawler->filter('select[name="models_step[guesserChoice]"] option'), '300 models and the placeholder, in a plain select');
        self::assertCount(0, $crawler->filter('datalist'));
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'long-list'), strpos($html, 'models_step[guesserName]'), 'The text field is above the folded list');
    }

    public function testTheSavedKeyIsKeptWhenTheFieldIsEmptyAndOnlyForTheSameProviderAndAddress(): void
    {
        $client = $this->client(self::anthropicList());
        self::getContainer()->get(ModelSettings::class)->save(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-haiku-5-5', false, self::KEY);

        $crawler = $this->get($client, '/en/models');
        self::assertSelectorTextContains('.models-form', 'A key for Anthropic is saved (…3f9a)');
        self::assertSame('anthropic', $crawler->filter('.provider-cards input:checked')->attr('value'));
        $client->submit($crawler->selectButton('List the models')->form(['provider_step[apiKey]' => '']));
        self::assertResponseRedirects('/en/models/choose');

        // The models saved are the ones preselected.
        $crawler = $this->get($client, '/en/models/choose');
        self::assertSame('claude-haiku-5-5', self::selected($crawler, 'models_step[guesserChoice]'));
        self::assertSame('claude-sonnet-5-5', self::selected($crawler, 'models_step[drawerChoice]'));
        self::assertSame(['https://api.anthropic.com/v1/models?limit=1000'], $this->asked);

        // Another provider needs its own key.
        $crawler = $this->get($client, '/en/models');
        $client->submit($crawler->selectButton('List the models')->form(['provider_step[provider]' => 'openai', 'provider_step[apiKey]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'OpenAI needs an API key.');
        $this->noKeyInAnyPage();
    }

    public function testASavedKeyIsNeverSentToAnotherAddress(): void
    {
        $client = $this->client(['data' => [['id' => 'qwen']]]);
        self::getContainer()->get(ModelSettings::class)->save(Provider::Compatible, 'https://openrouter.ai/api', 'qwen', 'qwen', false, 'sk-or-secret-0123456789abcdef');

        $crawler = $this->get($client, '/en/models');
        $client->submit($crawler->selectButton('List the models')->form(['provider_step[provider]' => 'openai_compatible', 'provider_step[apiKey]' => '', 'provider_step[address]' => 'https://evil.example']));
        $this->get($client, '/en/models/choose');

        // The address changed: the key is not carried along, the list is asked without it.
        self::assertSame(['https://evil.example/v1/models'], $this->asked);
        $client->request('GET', '/en/models/check');
        $this->noKeyInAnyPage('sk-or-secret-0123456789abcdef');
    }

    public function testWithNoModelTheHomePageInvitesToChooseOne(): void
    {
        $client = $this->client(self::anthropicList());

        $crawler = $client->request('GET', '/fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.health[data-controller]', 'No failing health card without a model');
        self::assertSame('/fr/models', $crawler->filter('.no-model a.button')->attr('href'));
        self::assertStringNotContainsStringIgnoringCase('ollama', (string) $client->getResponse()->getContent());

        // A game cannot start: the player is led to the screen.
        $client->request('POST', '/fr/games');
        self::assertResponseRedirects('/fr/models');

        $client->request('GET', '/fr/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($report['ok']);
        self::assertSame([], $report['roles']);
    }

    public function testASavedCompatibleSettingPrefillsItsCardAndItsAddress(): void
    {
        $client = $this->client(['data' => [['id' => 'qwen']]]);
        self::getContainer()->get(ModelSettings::class)->save(Provider::Compatible, 'http://host.docker.internal:8080', 'qwen', 'qwen', false);

        $crawler = $this->get($client, '/en/models');

        self::assertSame('openai_compatible', $crawler->filter('.provider-cards input:checked')->attr('value'));
        self::assertSame('http://host.docker.internal:8080', $crawler->filter('input[name="provider_step[address]"]')->attr('value'));
    }

    public function testTheHintsOfTheKeyAndTheAddressNameNoEngineAndSayNothingOfEncryption(): void
    {
        $client = $this->client(self::anthropicList());

        $crawler = $this->get($client, '/fr/models');

        $hints = $crawler->filter('.field .hint')->each(static fn (Crawler $hint): string => trim($hint->text()));
        self::assertContains('Laisse vide si ton serveur n’en demande pas.', $hints);
        self::assertContains('L’adresse de ton serveur. Le jeu tourne dans Docker, donc ta machine s’y appelle host.docker.internal, et pas localhost.', $hints);
        foreach ($hints as $hint) {
            self::assertStringNotContainsStringIgnoringCase('ollama', $hint);
            self::assertStringNotContainsStringIgnoringCase('chiffr', $hint);
        }

        // With a key saved, the hint says it is kept and shows its end, without the word.
        self::getContainer()->get(ModelSettings::class)->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
        $crawler = $this->get($client, '/fr/models');
        self::assertSelectorTextContains('.field .hint', '(…3f9a)');
        self::assertStringNotContainsStringIgnoringCase('chiffr', $crawler->filter('.field')->first()->text());
    }

    public function testTheAddressIsNeededAndMustBeAnAddress(): void
    {
        $client = $this->client(self::anthropicList());

        foreach (['' => 'This service needs an address.', 'gate:8001' => 'An address starts with http:// or https://.'] as $address => $message) {
            $crawler = $this->get($client, '/en/models');
            $client->submit($crawler->selectButton('List the models')->form(['provider_step[provider]' => 'openai_compatible', 'provider_step[address]' => $address]));

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.models-form', $message);
        }
    }

    public function testWithoutAnEncryptionKeyAKeyCannotBeKeptAndTheScreenSaysHow(): void
    {
        $client = $this->client(self::anthropicList());
        $client->getContainer()->set(Encryption::class, new Encryption(''));

        $this->step1($client);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'The API key cannot be kept: APP_ENCRYPTION_KEY is empty');
        self::assertSelectorTextContains('.models-form', 'make install');
        $this->noKeyInAnyPage();
        self::assertSame([], $this->asked);
    }

    public function testTheScreenSpeaksTheFiveLanguages(): void
    {
        $titles = ['en' => 'Choose the model', 'fr' => 'Choisir le modèle', 'de' => 'Das Modell wählen', 'es' => 'Elegir el modelo', 'it' => 'Scegliere il modello'];
        $client = $this->client(self::anthropicList());

        foreach ($titles as $locale => $title) {
            $client->request('GET', "/{$locale}/models");
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorTextContains('html[lang="'.$locale.'"] .provider-cards', 'Anthropic');
        }
    }

    public function testTheHomePageLeadsToTheScreen(): void
    {
        $client = $this->client(self::anthropicList(), ['circle', 'square'], chosen: true);

        // A permanent link at the bottom of the home page...
        $crawler = $client->request('GET', '/fr');
        self::assertSame('/fr/models', $crawler->filter('.change-model a')->attr('href'));
        self::assertSelectorTextContains('.change-model a', 'Changer de modèle');

        // ...and, when no model answers, the health card leads there instead of telling to edit .env.local.
        self::assertSame('/fr/models', $crawler->filter('.health-help a.button')->attr('href'));
        self::assertSelectorNotExists('.health-help:contains(".env.local")');
        $client->request('GET', '/fr/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('ne reconnaît pas un cercle', $report['roles'][1]['problem'], 'The health messages are translated');
    }

    public function testACrossSiteFormIsRefused(): void
    {
        $client = $this->client(self::anthropicList());

        $client->request('POST', '/en/models', ['provider_step' => ['provider' => 'anthropic', 'apiKey' => 'sk-attacker']], server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->asked, 'Nothing was listed');
    }
}
