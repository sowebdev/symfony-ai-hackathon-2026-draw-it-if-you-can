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
 * The models screen, one question at a time, on recorded lists (MockHttpClient) and the fake model: no provider is called.
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

    private function settings(): ModelSettings
    {
        return self::getContainer()->get(ModelSettings::class);
    }

    /** Anthropic stored for both roles, the guesser having its own row only when its model differs. */
    private function storeAnthropic(string $drawer = 'claude-sonnet-5-5', ?string $guesser = null): void
    {
        $this->settings()->choose(Role::Drawer, Provider::Anthropic, null, $drawer, false, self::KEY);
        if (null !== $guesser) {
            $this->settings()->choose(Role::Guesser, Provider::Anthropic, null, $guesser, false);
        }
    }

    /**
     * Question 1 of a role, answered; the next page's request is left to the caller.
     */
    private function chooseProvider(KernelBrowser $client, string $role, string $provider): void
    {
        $crawler = $this->get($client, "/en/models/{$role}");
        $client->submit($crawler->selectButton('Continue')->form(['provider_choice[provider]' => $provider]));
        $this->bodies[] = (string) $client->getResponse()->getContent();
    }

    private function giveAccess(KernelBrowser $client, string $role, string $key = self::KEY, ?string $address = null): void
    {
        $crawler = $this->get($client, "/en/models/{$role}/access");
        $values = ['access_step[apiKey]' => $key];
        if (null !== $address) {
            $values['access_step[address]'] = $address;
        }
        $client->submit($crawler->selectButton('Continue')->form($values));
        $this->bodies[] = (string) $client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function chooseModel(KernelBrowser $client, string $role, array $values = [], string $button = 'Check and play'): void
    {
        $crawler = $this->get($client, "/en/models/{$role}/model");
        $client->submit($crawler->selectButton($button)->form($values));
        $this->bodies[] = (string) $client->getResponse()->getContent();
    }

    /**
     * @return list<string> The models offered as radio buttons, cards and folded list together
     */
    private static function radios(Crawler $crawler): array
    {
        return $crawler->filter('input[type=radio][name="model_step[choice]"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
    }

    private function noKeyInAnyPage(string $key = self::KEY): void
    {
        foreach ($this->bodies as $body) {
            self::assertStringNotContainsString($key, $body, 'The API key never goes back to the browser');
        }
    }

    public function testTheFirstVisitStartsOnTheDrawerAndAsksOnlyWhichProvider(): void
    {
        $client = $this->client(self::anthropicList());

        $client->request('GET', '/en/models');
        self::assertResponseRedirects('/en/models/drawer');

        $crawler = $this->get($client, '/en/models/drawer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Which AI draws?');
        self::assertSame(['anthropic', 'openai', 'mistral', 'openai_compatible'], $crawler->filter('.provider-cards input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame(['Anthropic', 'OpenAI', 'Mistral', 'Your server, or another service'], $crawler->filter('.provider-card strong')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSame('anthropic', $crawler->filter('.provider-cards input:checked')->attr('value'));
        self::assertCount(0, $crawler->filter('input[type=password], input[type=text]'), 'Nothing else than the choice');
        self::assertCount(0, $crawler->filter('.model-steps'), 'No step indicator');
        self::assertCount(0, $crawler->filter('.saved-mark'), 'No key is stored');
        self::assertSelectorNotExists('.same-as-drawer', 'The drawer follows nobody');
        self::assertSelectorExists('button:contains("Continue")');
        self::assertCount(0, $crawler->filter('main.models script'));
    }

    public function testFirstVisitProviderThenKeyThenModelThenTheCheckSavesAndGoesHome(): void
    {
        $calls = new \ArrayObject();
        $client = $this->client(self::anthropicList(), calls: $calls);

        $this->chooseProvider($client, 'drawer', 'anthropic');
        self::assertResponseRedirects('/en/models/drawer/access');

        // Question 2: only the key, for a provider with a fixed address.
        $crawler = $this->get($client, '/en/models/drawer/access');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('label[for="access_step_apiKey"]', 'Your Anthropic key');
        self::assertCount(1, $crawler->filter('input[type=password]'));
        self::assertSame('', $crawler->filter('input[type=password]')->attr('value') ?? '');
        self::assertCount(0, $crawler->filter('input[name="access_step[address]"]'));
        self::assertCount(0, $crawler->filter('details'), 'The key of Anthropic is not folded away');

        // A key is needed for a place not stored yet.
        $client->submit($crawler->selectButton('Continue')->form(['access_step[apiKey]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'Anthropic needs an API key.');

        $this->giveAccess($client, 'drawer');
        self::assertResponseRedirects('/en/models/drawer/model');
        self::assertSame([], $this->asked, 'Nothing is listed before the key is given');

        // Question 3: the cheapest family is preselected and tagged; the key is in the session sealed.
        $crawler = $this->get($client, '/en/models/drawer/model');
        self::assertSelectorTextContains('h1', 'Which model draws?');
        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], self::radios($crawler));
        self::assertSame('claude-haiku-5-5', $crawler->filter('input[name="model_step[choice]"]:checked')->attr('value'));
        self::assertSelectorTextContains('.model-option.cheapest', 'the cheapest');
        self::assertSelectorNotExists('input[type=checkbox][name="model_step[reasoningOff]"]', 'Anthropic: the game decides about reasoning, no switch');
        self::assertSelectorTextContains('.follows-line', 'It guesses your drawing too.');
        self::assertSelectorTextContains('.follows-line button', 'Another AI to guess?');
        self::assertStringNotContainsString(self::KEY, json_encode($client->getRequest()->getSession()->all(), \JSON_THROW_ON_ERROR), 'Between the questions the key is sealed');

        $client->submit($crawler->selectButton('Check and play')->form());
        self::assertResponseRedirects('/en/models/check');
        self::assertFalse($this->settings()->isStored(), 'Nothing is saved before the check passes');

        // The check, on the spot: all pass, the setting is saved, and the player goes home.
        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        self::assertTrue($this->settings()->isStored());
        $drawer = $this->settings()->forRole(Role::Drawer);
        self::assertSame('claude-haiku-5-5', $drawer->model);
        self::assertSame(self::KEY, $drawer->apiKey());
        self::assertSame(Role::Drawer, $this->settings()->follows(Role::Guesser));
        self::assertSame('…3f9a', $this->settings()->accounts()[0]->maskedApiKey);
        $rows = (string) json_encode(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative('SELECT * FROM provider_account'));
        self::assertStringNotContainsString(self::KEY, $rows, 'The key is sealed in the database');

        // The home page does not check again what was just checked; the draft is gone.
        $before = \count($calls);
        $this->get($client, '/en');
        $client->request('GET', '/en/health');
        self::assertCount($before, $calls);
        self::assertArrayNotHasKey('model_draft', $client->getRequest()->getSession()->all());
        $this->noKeyInAnyPage();
    }

    public function testTheSummaryShowsOneLinePerRoleAndTheMutedLineOfAFollowingGuesser(): void
    {
        $client = $this->client(self::anthropicList());
        $this->storeAnthropic('claude-haiku-5-5');

        $crawler = $this->get($client, '/en/models');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'The game’s AIs');
        self::assertCount(1, $crawler->filter('.role-line:not(.follows)'));
        self::assertSelectorTextContains('.role-line:not(.follows)', 'It draws · claude-haiku-5-5 at Anthropic · change');
        self::assertSame('/en/models/drawer', $crawler->filter('.role-line:not(.follows) a')->attr('href'));
        self::assertSelectorTextContains('.role-line.follows', 'It guesses your drawing too.');
        self::assertSame('/en/models/guesser', $crawler->filter('.role-line.follows a')->attr('href'));
        self::assertSelectorTextContains('.role-line.follows a', 'Another AI to guess?');
        self::assertSelectorExists('button:contains("Check and play")');
        self::assertSelectorTextContains('details.saved-keys summary', 'Saved keys (1)');
        self::assertCount(0, $crawler->filter('details.saved-keys[open]'), 'Folded');
        $this->noKeyInAnyPage();

        // With a guesser of its own, one line each, "on your server" for a server.
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen3.8-27b-vision', true);
        $crawler = $this->get($client, '/en/models');
        self::assertCount(2, $crawler->filter('.role-line:not(.follows)'));
        self::assertSelectorNotExists('.role-line.follows');
        self::assertSelectorTextContains('.role-line:nth-child(2)', 'It guesses · qwen3.8-27b-vision on your server · change');

        $client->request('GET', '/fr/models');
        self::assertSelectorTextContains('.role-line:nth-child(1)', 'Il dessine · claude-haiku-5-5 chez Anthropic · changer');
        self::assertSelectorTextContains('.role-line:nth-child(2)', 'Il devine · qwen3.8-27b-vision sur ton serveur · changer');
        self::assertSelectorTextContains('h1', 'Les IA du jeu');
        self::assertSelectorTextContains('details.saved-keys summary', 'Clés enregistrées (2)');
    }

    public function testTheGuesserOnAnotherProviderThanTheDrawer(): void
    {
        $client = $this->client(['data' => [['id' => 'qwen3.8-27b-vision']]]);
        $this->storeAnthropic('claude-haiku-5-5');

        // The link of the summary starts the questions of the guesser; its own list has the other choice.
        $crawler = $this->get($client, '/en/models');
        $client->click($crawler->selectLink('Another AI to guess?')->link());
        self::assertSelectorTextContains('h1', 'Which AI guesses your drawing?');
        self::assertSelectorTextContains('.provider-anthropic .saved-mark', 'saved key');
        self::assertCount(1, $this->crawlerOf($client)->filter('.same-as-drawer button'));

        $this->chooseProvider($client, 'guesser', 'openai_compatible');
        self::assertResponseRedirects('/en/models/guesser/access');
        $crawler = $this->get($client, '/en/models/guesser/access');
        self::assertCount(1, $crawler->filter('input[name="access_step[address]"]'));
        self::assertSame('', $crawler->filter('input[name="access_step[address]"]')->attr('value') ?? '');
        self::assertSelectorTextContains('label[for="access_step_address"]', 'Its address');
        self::assertSelectorTextContains('details.long-list summary', 'Does it need a key?');
        self::assertCount(1, $crawler->filter('details.long-list input[type=password]'), 'The key is folded away');

        $this->giveAccess($client, 'guesser', '', 'http://gate:8001');
        self::assertResponseRedirects('/en/models/guesser/model');
        self::assertSame([], $this->asked, 'The models are listed when the question is asked');

        $crawler = $this->get($client, '/en/models/guesser/model');
        self::assertSame(['http://gate:8001/v1/models'], $this->asked);
        self::assertSelectorTextContains('h1', 'Which model guesses?');
        self::assertSelectorTextContains('.role-line', 'It draws · claude-haiku-5-5 at Anthropic', 'The drawer, already chosen, is one line on top');
        self::assertSame(['qwen3.8-27b-vision'], self::radios($crawler));
        self::assertSelectorNotExists('.follows-line');
        // The switch is folded behind "Advanced settings", on for a local address.
        self::assertSelectorTextContains('details.long-list summary', 'Advanced settings');
        self::assertSame('checked', $crawler->filter('input[name="model_step[reasoningOff]"]')->attr('checked') ?? '');
        $client->submit($crawler->selectButton('Check and play')->form(['model_step[choice]' => 'qwen3.8-27b-vision']));
        self::assertResponseRedirects('/en/models/check');

        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        $drawer = $this->settings()->forRole(Role::Drawer);
        $guesser = $this->settings()->forRole(Role::Guesser);
        self::assertSame([Provider::Anthropic, null, self::KEY, 'claude-haiku-5-5'], [$drawer->provider, $drawer->address, $drawer->apiKey(), $drawer->model]);
        self::assertSame([Provider::Compatible, 'http://gate:8001', null, 'qwen3.8-27b-vision', true], [$guesser->provider, $guesser->address, $guesser->apiKey(), $guesser->model, $guesser->reasoningOff]);
        self::assertNull($this->settings()->follows(Role::Guesser));
        $this->noKeyInAnyPage();
    }

    public function testTheDrawersLastQuestionLeadsToTheGuesserWithoutLosingItsModel(): void
    {
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');

        $crawler = $this->get($client, '/en/models/drawer/model');
        $client->submit($crawler->selectButton('Another AI to guess?')->form(['model_step[choice]' => 'claude-sonnet-5-5']));
        self::assertResponseRedirects('/en/models/guesser');

        // The drawer, chosen in the draft only, is the line on top; Anthropic is tagged: its key was typed a moment ago.
        $crawler = $this->get($client, '/en/models/guesser');
        self::assertSelectorTextContains('.role-line', 'It draws · claude-sonnet-5-5 at Anthropic');
        self::assertSelectorTextContains('.provider-anthropic .saved-mark', 'saved key');
        self::assertFalse($this->settings()->isStored());

        // The same place, known from the draft: no key to give again, straight to the models.
        $client->submit($crawler->selectButton('Continue')->form(['provider_choice[provider]' => 'anthropic']));
        self::assertResponseRedirects('/en/models/guesser/model');
        $crawler = $this->get($client, '/en/models/guesser/model');
        self::assertSelectorNotExists('.follows-line', 'The drawer has no other question to go on to');
        $client->submit($crawler->selectButton('Check and play')->form(['model_step[choice]' => 'claude-haiku-5-5']));
        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');

        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Drawer)->model);
        self::assertSame('claude-haiku-5-5', $this->settings()->forRole(Role::Guesser)->model);
        self::assertSame(self::KEY, $this->settings()->forRole(Role::Guesser)->apiKey());
        self::assertCount(1, $this->settings()->accounts());
        $this->noKeyInAnyPage();
    }

    public function testTheSameAIAsTheDrawerRemovesTheRowOfTheGuesser(): void
    {
        $client = $this->client(self::anthropicList());
        $this->storeAnthropic('claude-sonnet-5-5', 'claude-haiku-5-5');
        self::assertNull($this->settings()->follows(Role::Guesser));

        $crawler = $this->get($client, '/en/models/guesser');
        $client->submit($crawler->selectButton('The same AI as the one that draws')->form());
        self::assertResponseRedirects('/en/models');

        $crawler = $this->get($client, '/en/models');
        self::assertSelectorTextContains('.role-line.follows', 'It guesses your drawing too.');
        self::assertSelectorNotExists('.role-line:not(.follows) + .role-line:not(.follows)');
        self::assertNull($this->settings()->follows(Role::Guesser), 'Nothing is changed before the check passes');

        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        self::assertSame(Role::Drawer, $this->settings()->follows(Role::Guesser));
        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Guesser)->model);
    }

    public function testAStoredKeyIsNotAskedAgainAndCanBeChanged(): void
    {
        $client = $this->client(self::anthropicList());
        $this->storeAnthropic('claude-haiku-5-5');

        // The provider of a stored place goes straight to the models.
        $this->chooseProvider($client, 'drawer', 'anthropic');
        self::assertResponseRedirects('/en/models/drawer/model');

        // A stored model is the one preselected.
        $crawler = $this->get($client, '/en/models/drawer/model');
        self::assertSame(['https://api.anthropic.com/v1/models?limit=1000'], $this->asked);
        self::assertSame('claude-haiku-5-5', $crawler->filter('input[name="model_step[choice]"]:checked')->attr('value'));

        // Changing the key goes through the second question, which shows the field again.
        $crawler = $this->get($client, '/en/models/drawer/access');
        self::assertSelectorTextContains('label[for="access_step_apiKey"]', 'Change the key');
        self::assertSelectorTextContains('.field .hint', '(…3f9a)');
        $client->submit($crawler->selectButton('Continue')->form(['access_step[apiKey]' => '']));
        self::assertResponseRedirects('/en/models/drawer/model');

        $newKey = 'sk-ant-api03-fedcba9876543210ffff';
        $this->giveAccess($client, 'drawer', $newKey);
        $this->chooseModel($client, 'drawer');
        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        self::assertSame($newKey, $this->settings()->forRole(Role::Drawer)->apiKey());
        self::assertSame('…ffff', $this->settings()->accounts()[0]->maskedApiKey);
        self::assertCount(1, $this->settings()->accounts());
        $this->noKeyInAnyPage($newKey);
        $this->noKeyInAnyPage();
    }

    public function testAStoredServerAndItsKeyAreFoundAgainByItsAddress(): void
    {
        $client = $this->client(['data' => [['id' => 'qwen']]]);
        $this->settings()->choose(Role::Drawer, Provider::Compatible, 'https://openrouter.ai/api', 'qwen', false, 'sk-or-secret-0123456789abcdef');

        $crawler = $this->get($client, '/en/models/drawer');
        self::assertSelectorTextContains('.provider-openai_compatible .saved-mark', 'saved key');
        self::assertSame('openai_compatible', $crawler->filter('.provider-cards input:checked')->attr('value'));
        $client->submit($crawler->selectButton('Continue')->form());

        // The address of the role is the one proposed; with the same address, the stored key goes with it.
        $crawler = $this->get($client, '/en/models/drawer/access');
        self::assertSame('https://openrouter.ai/api', $crawler->filter('input[name="access_step[address]"]')->attr('value'));
        self::assertSelectorTextContains('label[for="access_step_apiKey"]', 'Change the key');
        $client->submit($crawler->selectButton('Continue')->form());
        $this->get($client, '/en/models/drawer/model');
        self::assertSame(['https://openrouter.ai/api/v1/models'], $this->asked);

        // Another address is another place: the key is not carried along, the list is asked without it.
        $this->asked = [];
        $this->giveAccess($client, 'drawer', '', 'https://evil.example');
        $this->get($client, '/en/models/drawer/model');
        self::assertSame(['https://evil.example/v1/models'], $this->asked);
        $client->request('GET', '/en/models/check');
        $this->noKeyInAnyPage('sk-or-secret-0123456789abcdef');
    }

    public function testAFailedCheckOnOneRoleOnlyKeepsTheOtherVisibleAndSavesNothing(): void
    {
        // The drawer names the circle, the guesser says "square" and then nothing.
        $calls = new \ArrayObject();
        $client = $this->client(self::anthropicList(), ['circle', 'square'], calls: $calls);

        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');
        $crawler = $this->get($client, '/en/models/drawer/model');
        $client->submit($crawler->selectButton('Another AI to guess?')->form(['model_step[choice]' => 'claude-sonnet-5-5']));
        $this->chooseProvider($client, 'guesser', 'anthropic');
        $this->chooseModel($client, 'guesser', ['model_step[choice]' => 'claude-haiku-5-5']);

        $crawler = $this->get($client, '/en/models/check');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.role-line.ok'));
        self::assertCount(1, $crawler->filter('.role-line.failed'));
        self::assertSelectorTextContains('.role-line.ok', 'It draws · claude-sonnet-5-5 at Anthropic');
        self::assertSelectorTextContains('.role-line.failed', 'It guesses · claude-haiku-5-5 at Anthropic');
        self::assertSelectorTextContains('.role-line.failed .why', 'does not recognize a circle');
        self::assertSelectorTextContains('.role-line.failed .why', '(it answered “square”)');
        self::assertSame('/en/models/guesser', $crawler->filter('.role-line.failed a')->attr('href'));
        self::assertSelectorTextContains('.role-line.failed button', 'Try again');
        self::assertCount(1, $crawler->filter('button:contains("Try again")'), 'Only the failed role can be tried again');
        self::assertSelectorNotExists('main > form button:contains("Check and play")');
        self::assertFalse($this->settings()->isStored());

        // Trying again does not call the model that passed.
        $before = \count($calls);
        $crawler = $client->request('GET', '/en/models/check');
        self::assertSame(1, \count($calls) - $before, 'Only the guesser is tried again');
        self::assertCount(1, $crawler->filter('.role-line.ok'));
        self::assertCount(1, $crawler->filter('.role-line.failed'));

        // "Change": the guesser is the same AI as the drawer, which has passed: the check passes and saves.
        $crawler = $this->get($client, '/en/models/guesser');
        $client->submit($crawler->selectButton('The same AI as the one that draws')->form());
        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Guesser)->model);
        self::assertSame(Role::Drawer, $this->settings()->follows(Role::Guesser));
    }

    public function testAFailureOfTheDrawerSaysWhyAndAChangeLeavesWhatIsStored(): void
    {
        // A text-only drawer: it does not name the circle.
        $client = $this->client(self::anthropicList(), ['ready']);
        $this->storeAnthropic('claude-sonnet-5-5');

        $crawler = $this->get($client, '/en/models/check');

        self::assertCount(1, $crawler->filter('.role-line.failed'));
        self::assertSelectorTextContains('.role-line.failed', 'It draws');
        self::assertSelectorTextContains('.role-line.failed .why', 'must be a model that reads images');
        self::assertSelectorTextContains('.role-line.follows', 'It guesses your drawing too.');
        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Drawer)->model);
    }

    public function testASavedAccountIsForgottenOnlyWhenNoRoleUsesIt(): void
    {
        $client = $this->client(self::anthropicList());
        $this->storeAnthropic('claude-haiku-5-5');
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen', false);
        $this->settings()->save([Role::Guesser->value => null]);
        [$used, $free] = $this->settings()->accounts();
        self::assertTrue($used->isUsed());
        self::assertFalse($free->isUsed(), 'The server stays after the guesser left it');

        $crawler = $this->get($client, '/en/models');

        self::assertSelectorTextContains('details.saved-keys summary', 'Saved keys (2)');
        self::assertCount(2, $crawler->filter('.saved-keys li'));
        self::assertSelectorTextContains('.saved-keys li:nth-child(1)', '…3f9a');
        self::assertSelectorTextContains('.saved-keys li:nth-child(1)', 'It draws with it');
        self::assertCount(1, $crawler->filter('.saved-keys li:nth-child(1) button[disabled]'));
        self::assertSelectorTextContains('.saved-keys li:nth-child(2)', 'http://gate:8001');
        self::assertCount(0, $crawler->filter('.saved-keys li:nth-child(2) button[disabled]'));

        // A forget posted for the account in use is refused, even with the token of another form.
        $form = $crawler->filter('.saved-keys li:nth-child(2) form')->form();
        $values = $form->getPhpValues();
        $client->request('POST', "/en/models/keys/{$used->id}/forget", ['forget_'.$used->id => $values['forget_'.$free->id]]);
        self::assertResponseRedirects('/en/models');
        self::assertCount(2, $this->settings()->accounts());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.error', 'A key an AI uses cannot be forgotten.');

        // The free one is forgotten.
        $form = $crawler->filter('.saved-keys li:nth-child(2) form')->form();
        $client->submit($form);
        self::assertResponseRedirects('/en/models');
        self::assertCount(1, $this->settings()->accounts());
        self::assertSame(Provider::Anthropic, $this->settings()->accounts()[0]->provider);
        $client->followRedirect();
        self::assertSelectorTextContains('.notice', 'The key is forgotten.');
        self::assertSelectorNotExists('.saved-keys li:nth-child(2)');
    }

    public function testAForgetWithoutTheTokenOfTheScreenIsRefused(): void
    {
        $client = $this->client(self::anthropicList());
        $this->storeAnthropic('claude-haiku-5-5');
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen', false);
        $this->settings()->save([Role::Guesser->value => null]);
        $free = $this->settings()->accounts()[1];

        $client->request('POST', "/en/models/keys/{$free->id}/forget", [], server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'localhost']);

        self::assertCount(2, $this->settings()->accounts());
    }

    public function testTheKeyIsInTheSessionSealedAndNeverInAResponse(): void
    {
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');
        $this->get($client, '/en/models/drawer/model');

        $session = $client->getRequest()->getSession()->all();
        self::assertStringNotContainsString(self::KEY, json_encode($session, \JSON_THROW_ON_ERROR), 'Between the questions the key is sealed');
        self::assertNotSame('', $session['model_draft']['edit']['key'] ?? '');
        $this->noKeyInAnyPage();
        self::assertStringNotContainsString(self::KEY, implode("\n", $this->asked));

        // Abandoned: on the home page, the draft is dropped.
        $this->get($client, '/en');
        self::assertArrayNotHasKey('model_draft', $client->getRequest()->getSession()->all());
    }

    public function testAUnreadableListSaysWhyAndLeavesTheTextField(): void
    {
        $client = $this->client(null);
        $this->chooseProvider($client, 'drawer', 'openai_compatible');
        $this->giveAccess($client, 'drawer', '', 'http://gate:8001');

        $crawler = $this->get($client, '/en/models/drawer/model');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.models-problem', 'The list of models cannot be read.');
        self::assertSelectorTextContains('.models-problem', 'Nothing answers at http://gate:8001');
        self::assertCount(0, $crawler->filter('input[type=radio]'));
        self::assertCount(1, $crawler->filter('input[type=text]'));

        $client->submit($crawler->selectButton('Check and play')->form(['model_step[name]' => ' qwen ']));
        self::assertResponseRedirects('/en/models/check');
        $client->request('GET', '/en/models/check');
        self::assertResponseRedirects('/en');
        self::assertSame('qwen', $this->settings()->forRole(Role::Drawer)->model);
    }

    public function testAModelMustBeChosenOrTyped(): void
    {
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');

        $crawler = $this->get($client, '/en/models/drawer/model');
        // The preselected model is only a first choice: the player may leave none.
        $form = $crawler->selectButton('Check and play')->form();
        unset($form['model_step[choice]']);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'Choose a model in the list, or type its name.');

        // A name outside the list is refused as a choice, accepted when typed.
        $client->submit($crawler->selectButton('Check and play')->form(['model_step[name]' => 'claude-brand-new-9']));
        self::assertResponseRedirects('/en/models/check');
    }

    public function testOlderVersionsAreFoldedAwayAndTheCardsGoFromTheCheapest(): void
    {
        $ids = ['claude-opus-5-5', 'claude-haiku-4-5-20251001', 'claude-sonnet-5-5', 'claude-haiku-5-5'];
        $client = $this->client(['data' => array_map(static fn (string $id): array => ['id' => $id], $ids), 'has_more' => false]);
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');

        $crawler = $this->get($client, '/en/models/drawer/model');

        $cards = $crawler->filter('.model-cards')->first()->filter('input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], $cards);
        self::assertSame(['claude-haiku-4-5-20251001'], $crawler->filter('details.long-list')->first()->filter('input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSelectorTextContains('details.long-list summary', 'See all models');
        self::assertCount(0, $crawler->filter('details.long-list[open]'));
    }

    public function testAModelOfUnknownCapabilitiesIsAmongTheOthersMarkedAndNeverACard(): void
    {
        $client = $this->client(['data' => [['id' => 'claude-haiku-5-5'], ['id' => 'claude-fable-5-2']], 'has_more' => false]);
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');

        $crawler = $this->get($client, '/en/models/drawer/model');

        self::assertSame(['claude-haiku-5-5'], $crawler->filter('.model-cards')->first()->filter('input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame(['claude-fable-5-2'], $crawler->filter('details.long-list')->first()->filter('input[type=radio]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSelectorTextContains('details.long-list .unknown-mark', 'capabilities unknown');
        self::assertCount(0, $crawler->filter('.model-cards .unknown-mark'));
    }

    public function testTheWaitForTheCheckShowsTheLoadingScreen(): void
    {
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');

        $crawler = $this->get($client, '/en/models/drawer/model');
        self::assertSame('loading#show', $crawler->filter('form.models-form')->attr('data-action'));
        self::assertSelectorTextContains('#loading .loading-title', 'Checking the models');
        self::assertSame('loading#show', $crawler->filter('.follows-line')->ancestors()->filter('form')->attr('data-action'));
        self::assertSame('data-plain', $crawler->filter('.follows-line button')->attr('data-plain'), 'The button that goes on to another question does not show the screen');

        $this->storeAnthropic();
        $crawler = $this->get($client, '/en/models');
        self::assertSame('loading#show', $crawler->filter('form[action="/en/models/check"]')->attr('data-action'));
        self::assertSelectorExists('#loading');
    }

    public function testAnotherServerShowsTheReasoningSwitchOnOnlyForALocalAddress(): void
    {
        foreach (['http://host.docker.internal:11434' => true, 'http://localhost:1234' => true, 'http://192.168.1.20:8080' => true, 'https://openrouter.ai/api' => false] as $address => $on) {
            self::ensureKernelShutdown();
            $client = $this->client(['data' => [['id' => 'qwen3.8:27b']]]);
            $this->chooseProvider($client, 'drawer', 'openai_compatible');
            $this->giveAccess($client, 'drawer', 'sk-or', $address);
            $crawler = $this->get($client, '/en/models/drawer/model');

            self::assertSelectorTextContains('details.long-list .switch-label', 'Turn reasoning off, for a local model that thinks for too long');
            self::assertSame($on, null !== $crawler->filter('input[name="model_step[reasoningOff]"]')->attr('checked'), $address);
            self::assertSame($on, 1 === $crawler->filter('details.long-list[open]')->count(), 'Folded unless it is on');
        }
    }

    public function testOnlyTheOtherServerHasTheSwitchAndTheModelsOfAListWithoutCapabilitiesSaySo(): void
    {
        $client = $this->client(['data' => [['id' => 'zeta'], ['id' => 'alpha']]]);
        $this->chooseProvider($client, 'drawer', 'openai_compatible');
        $this->giveAccess($client, 'drawer', '', 'http://gate:8001');

        $crawler = $this->get($client, '/en/models/drawer/model');

        self::assertSame(['alpha', 'zeta'], self::radios($crawler));
        self::assertCount(0, $crawler->filter('input[name="model_step[choice]"]:checked'), 'No table of families: nothing is preselected');
        self::assertCount(1, $crawler->filter('details.long-list'), 'A short list of a server of your own is shown as it is: only the advanced settings are folded');
        self::assertSelectorTextContains('.models-form', 'capabilities unknown, the check will tell');

        // The switch is for the other server only.
        self::ensureKernelShutdown();
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'anthropic');
        $this->giveAccess($client, 'drawer');
        $this->get($client, '/en/models/drawer/model');
        self::assertSelectorNotExists('input[name="model_step[reasoningOff]"]');
    }

    public function testAHugeListIsFoldedAndTheTextFieldComesFirst(): void
    {
        $entries = array_map(static fn (int $i): array => ['id' => \sprintf('vendor/model-%03d', $i)], range(1, 300));
        $client = $this->client(['data' => $entries]);
        $this->chooseProvider($client, 'drawer', 'openai_compatible');
        $this->giveAccess($client, 'drawer', 'sk-or', 'https://openrouter.ai/api');

        $crawler = $this->get($client, '/en/models/drawer/model');

        self::assertSelectorTextContains('details.long-list summary', 'Pick from the 300 models');
        self::assertCount(300, $crawler->filter('details.long-list')->first()->filter('input[type=radio]'));
        self::assertCount(0, $crawler->filter('.model-cards'));
        self::assertCount(0, $crawler->filter('datalist'));
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'long-list'), strpos($html, 'model_step[name]'), 'The text field is above the folded list');
    }

    public function testTheAddressIsNeededAndMustBeAnAddress(): void
    {
        $client = $this->client(self::anthropicList());
        $this->chooseProvider($client, 'drawer', 'openai_compatible');

        foreach (['' => 'This service needs an address.', 'gate:8001' => 'An address starts with http:// or https://.'] as $address => $message) {
            $this->giveAccess($client, 'drawer', '', $address);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.models-form', $message);
        }
        self::assertSame([], $this->asked);
    }

    public function testWithoutAnEncryptionKeyAKeyCannotBeKeptAndTheScreenSaysHow(): void
    {
        $client = $this->client(self::anthropicList());
        $client->getContainer()->set(Encryption::class, new Encryption(''));
        $this->chooseProvider($client, 'drawer', 'anthropic');

        $this->giveAccess($client, 'drawer');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.models-form', 'The API key cannot be kept: APP_ENCRYPTION_KEY is empty');
        self::assertSelectorTextContains('.models-form', 'make install');
        $this->noKeyInAnyPage();
        self::assertSame([], $this->asked);
    }

    public function testTheQuestionsSpeakTheFiveLanguages(): void
    {
        $titles = ['en' => 'Which AI draws?', 'fr' => 'Quelle IA dessine ?', 'de' => 'Welche KI zeichnet?', 'es' => '¿Qué IA dibuja?', 'it' => 'Quale IA disegna?'];
        $client = $this->client(self::anthropicList());

        foreach ($titles as $locale => $title) {
            $client->request('GET', "/{$locale}/models/drawer");
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorTextContains('html[lang="'.$locale.'"] .provider-cards', 'Anthropic');
        }
        $client->request('GET', '/fr/models/guesser');
        self::assertSelectorTextContains('h1', 'Quelle IA devine ton dessin ?');
        self::assertSelectorTextContains('.provider-openai_compatible', 'Ton serveur, ou un autre service');
        self::assertSelectorTextContains('.same-as-drawer button', 'La même IA que celui qui dessine');
    }

    public function testInFrenchTheKeyAndTheAddressAreAskedInTheWordsOfTheScreen(): void
    {
        $client = $this->client(self::anthropicList());
        $crawler = $client->request('GET', '/fr/models/drawer');
        $client->submit($crawler->selectButton('Continuer')->form(['provider_choice[provider]' => 'anthropic']));
        $client->request('GET', '/fr/models/drawer/access');
        self::assertSelectorTextContains('label[for="access_step_apiKey"]', 'Ta clé Anthropic');

        $crawler = $client->request('GET', '/fr/models/drawer');
        $client->submit($crawler->selectButton('Continuer')->form(['provider_choice[provider]' => 'openai_compatible']));
        $client->request('GET', '/fr/models/drawer/access');
        self::assertSelectorTextContains('label[for="access_step_address"]', 'Son adresse');
        self::assertSelectorTextContains('details summary', 'Il faut une clé ?');
        self::assertSelectorTextContains('.field .hint', 'host.docker.internal');
        $hints = $client->getCrawler()->filter('.field .hint')->each(static fn (Crawler $hint): string => trim($hint->text()));
        foreach ($hints as $hint) {
            self::assertStringNotContainsStringIgnoringCase('ollama', $hint);
            self::assertStringNotContainsStringIgnoringCase('chiffr', $hint);
        }
    }

    public function testWithNoModelTheHomePageInvitesToChooseOne(): void
    {
        $client = $this->client(self::anthropicList());

        $crawler = $client->request('GET', '/fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.health[data-controller]', 'No failing health card without a model');
        self::assertSame('/fr/models', $crawler->filter('.no-model a.button')->attr('href'));

        // A game cannot start: the player is led to the screen.
        $client->request('POST', '/fr/games');
        self::assertResponseRedirects('/fr/models');

        $client->request('GET', '/fr/health?fresh=1');
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($report['ok']);
        self::assertSame([], $report['roles']);

        // Without a drawer, the check goes to the first question.
        $client->request('GET', '/fr/models/check');
        self::assertResponseRedirects('/fr/models/drawer');
    }

    public function testTheStepsOfARoleAreOrderedAndTheirFirstQuestionIsTheWayIn(): void
    {
        $client = $this->client(self::anthropicList());

        // Without the answers before, a question starts again from the first.
        foreach (['access', 'model'] as $step) {
            $client->request('GET', "/en/models/guesser/{$step}");
            self::assertResponseRedirects('/en/models/guesser');
        }
        $client->request('GET', '/en/models/judge');
        self::assertResponseStatusCodeSame(404);
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

        $client->request('POST', '/en/models/drawer', ['provider_choice' => ['provider' => 'anthropic']], server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->asked, 'Nothing was listed');

        $client->request('POST', '/en/models/guesser/same', server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'localhost']);
        self::assertArrayNotHasKey('model_draft', $client->getRequest()->getSession()->all());
    }

    private function crawlerOf(KernelBrowser $client): Crawler
    {
        return $client->getCrawler();
    }
}
