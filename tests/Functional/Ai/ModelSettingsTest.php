<?php

namespace App\Tests\Functional\Ai;

use App\Ai\Encryption;
use App\Ai\EncryptionException;
use App\Ai\Models;
use App\Ai\ModelSettings;
use App\Ai\NoModelException;
use App\Ai\PlatformFactory;
use App\Ai\Provider;
use App\Ai\Role;
use App\Ai\RoleSetup;
use App\Repository\ProviderAccountRepository;
use App\Repository\RoleModelRepository;
use App\Tests\Functional\RecordingPlatforms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The setting in the database, its API key sealed, and the game reading it: the only source of the model.
 */
final class ModelSettingsTest extends KernelTestCase
{
    private const string KEY = 'sk-ant-api03-0123456789abcdef3f9a';

    private RecordingPlatforms $platforms;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->platforms = new RecordingPlatforms();
        self::getContainer()->set(PlatformFactory::class, $this->platforms);
    }

    private function settings(): ModelSettings
    {
        return self::getContainer()->get(ModelSettings::class);
    }

    private function models(): Models
    {
        return self::getContainer()->get(Models::class);
    }

    /**
     * The same place for both roles, each with its model.
     */
    private function both(Provider $provider, ?string $address, string $drawerModel, string $guesserModel, bool $reasoningOff, ?string $apiKey = null): void
    {
        $this->bothOn($this->settings(), $provider, $address, $drawerModel, $guesserModel, $reasoningOff, $apiKey);
    }

    private function bothOn(ModelSettings $settings, Provider $provider, ?string $address, string $drawerModel, string $guesserModel, bool $reasoningOff, ?string $apiKey = null): void
    {
        $settings->save([
            Role::Drawer->value => new RoleSetup($provider, $address, $apiKey, $drawerModel, $reasoningOff),
            Role::Guesser->value => new RoleSetup($provider, $address, $apiKey, $guesserModel, $reasoningOff),
        ]);
    }

    private function ask(Models $models, Role $role = Role::Drawer): void
    {
        $models->invoke($role, new MessageBag(Message::ofUser('hello')), maxTokens: 123)->asText();
    }

    public function testWithoutASettingTheGameHasNoModel(): void
    {
        self::assertFalse($this->settings()->isStored());
        self::assertSame([], $this->settings()->views());

        $this->expectException(NoModelException::class);
        $this->settings()->forRole(Role::Drawer);
    }

    public function testAGuesserWithoutARowOfItsOwnCallsTheModelOfTheDrawer(): void
    {
        $this->settings()->choose(Role::Drawer, Provider::Anthropic, null, 'claude-haiku-5-5', false, self::KEY);

        $drawer = $this->settings()->forRole(Role::Drawer);
        $guesser = $this->settings()->forRole(Role::Guesser);

        self::assertSame('claude-haiku-5-5', $guesser->model);
        self::assertSame(Provider::Anthropic, $guesser->provider);
        self::assertSame(self::KEY, $guesser->apiKey());
        self::assertSame($drawer->fingerprint(), $guesser->fingerprint());
        self::assertSame(Role::Drawer, $this->settings()->follows(Role::Guesser));
        self::assertNull($this->settings()->follows(Role::Drawer));
        self::assertArrayNotHasKey('guesser', $this->settings()->views());
    }

    public function testTwoRolesOnTwoProvidersEachCallTheirOwn(): void
    {
        $this->settings()->choose(Role::Drawer, Provider::Anthropic, null, 'claude-haiku-5-5', false, self::KEY);
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen3.8-27b-vision', true);

        $drawer = $this->settings()->forRole(Role::Drawer);
        $guesser = $this->settings()->forRole(Role::Guesser);

        self::assertSame(Provider::Anthropic, $drawer->provider);
        self::assertNull($drawer->address);
        self::assertSame(self::KEY, $drawer->apiKey());
        self::assertFalse($drawer->reasoningOff);
        self::assertSame(Provider::Compatible, $guesser->provider);
        self::assertSame('http://gate:8001', $guesser->address);
        self::assertNull($guesser->apiKey(), 'The key of Anthropic does not follow the guesser to another place');
        self::assertSame('qwen3.8-27b-vision', $guesser->model);
        self::assertTrue($guesser->reasoningOff);
        self::assertNull($this->settings()->follows(Role::Guesser));

        // Through Models, each role asks its own platform.
        $this->ask($this->models(), Role::Drawer);
        $this->ask($this->models(), Role::Guesser);
        self::assertSame([Provider::Anthropic, null, self::KEY], $this->platforms->created[0]);
        self::assertSame([Provider::Compatible, 'http://gate:8001', null], $this->platforms->created[1]);
    }

    public function testASavedKeyIsReusedOnlyForTheSameProviderAndAddress(): void
    {
        $this->settings()->choose(Role::Drawer, Provider::Compatible, 'http://gate:8001/', 'qwen', false, 'sk-gate-0123456789abcdef0123');

        self::assertSame('sk-gate-0123456789abcdef0123', $this->settings()->savedKeyFor(Provider::Compatible, 'http://gate:8001'), 'A trailing slash is the same place');
        self::assertNull($this->settings()->savedKeyFor(Provider::Compatible, 'http://other:8001'));
        self::assertNull($this->settings()->savedKeyFor(Provider::Anthropic, null));
        self::assertTrue($this->settings()->hasKeyFor(Provider::Compatible));
        self::assertFalse($this->settings()->hasKeyFor(Provider::Mistral));

        // A role on another address of the same provider does not get the key of the first.
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://other:8001', 'qwen', false);
        self::assertNull($this->settings()->forRole(Role::Guesser)->apiKey());
        self::assertSame('sk-gate-0123456789abcdef0123', $this->settings()->forRole(Role::Drawer)->apiKey());
    }

    public function testAFixedProviderIsOneAccountWhateverAddressIsGiven(): void
    {
        $this->settings()->choose(Role::Drawer, Provider::Anthropic, null, 'claude-haiku-5-5', false, self::KEY);
        $this->settings()->choose(Role::Guesser, Provider::Anthropic, 'http://ignored', 'claude-sonnet-5-5', false);

        self::assertCount(1, $this->settings()->accounts());
        self::assertSame(self::KEY, $this->settings()->forRole(Role::Guesser)->apiKey());
    }

    public function testASettingWithoutADrawerIsNoModel(): void
    {
        $this->settings()->choose(Role::Guesser, Provider::Compatible, 'http://gate:8001', 'qwen', false);

        self::assertFalse($this->settings()->isStored());
        $this->expectException(NoModelException::class);
        $this->settings()->forRole(Role::Guesser);
    }

    public function testAGuesserThatFollowsAgainLosesItsRowAndTheRolesAreSavedAllOrNone(): void
    {
        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen', 'qwen-vl', false);
        $this->settings()->save([Role::Guesser->value => null]);
        self::assertSame('qwen', $this->settings()->forRole(Role::Guesser)->model);

        try {
            $this->settings()->save([
                Role::Drawer->value => new RoleSetup(Provider::Compatible, 'http://gate:8001', null, 'other', false),
                Role::Guesser->value => new RoleSetup(Provider::OpenAi, null, null, 'gpt-4o', false),
            ]);
            self::fail('OpenAI has no key');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('qwen', $this->settings()->forRole(Role::Drawer)->model, 'Nothing of an incomplete save is kept');
    }

    public function testTheStoredSettingIsWhatTheGameCalls(): void
    {
        $this->both(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-haiku-5-5', true, self::KEY);

        $drawer = $this->settings()->forRole(Role::Drawer);
        $guesser = $this->settings()->forRole(Role::Guesser);
        self::assertSame(Provider::Anthropic, $drawer->provider);
        self::assertSame('claude-sonnet-5-5', $drawer->model);
        self::assertSame('claude-haiku-5-5', $guesser->model);
        self::assertSame(self::KEY, $drawer->apiKey());

        $this->ask($this->models());
        self::assertSame([Provider::Anthropic, null, self::KEY], $this->platforms->created[0]);
        self::assertSame('claude-sonnet-5-5', $this->platforms->invoked[0][0]);
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[0][1], 'Anthropic gets no reasoning option: its API would answer 400');
    }

    public function testEachProviderGetsItsOwnOptionsThroughModels(): void
    {
        $this->both(Provider::Mistral, null, 'mistral-medium-3.5', 'mistral-small-latest', true, 'sk-mistral');
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[0][1], 'Mistral: the game sends nothing for reasoning, a switch or not');

        // Another OpenAI-compatible service: the player's switch decides.
        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen3', 'qwen3', true);
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123, 'chat_template_kwargs' => ['enable_thinking' => false], 'reasoning_effort' => 'none'], $this->platforms->invoked[1][1]);
        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen3', 'qwen3', false);
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[2][1], 'Reasoning is left alone when the switch is off');
        self::assertFalse($this->settings()->views()['drawer']->reasoningOff);

        // gpt-5.1 thinks (the catalog says so), gpt-4o does not.
        $this->both(Provider::OpenAi, null, 'gpt-5.1', 'gpt-4o', true, 'sk-openai');
        $this->ask($this->models(), Role::Drawer);
        $this->ask($this->models(), Role::Guesser);
        self::assertSame(['max_output_tokens' => 123, 'reasoning' => ['effort' => 'low']], $this->platforms->invoked[3][1]);
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[4][1]);
        self::assertFalse($this->settings()->views()['drawer']->reasoningOff, 'The switch of a provider that has none is not kept');

        // A model typed by name, not in the catalog: no reasoning option.
        $this->both(Provider::OpenAi, null, 'gpt-9-not-yet-released', 'gpt-9-not-yet-released', false);
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[5][1]);
    }

    public function testTheKeyIsSealedInTheDatabaseAndTheScreenGetsItsEndOnly(): void
    {
        $this->both(Provider::OpenAi, null, 'gpt-4o', 'gpt-4o', false, self::KEY);

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM provider_account');
        self::assertIsArray($row);
        self::assertStringNotContainsString(self::KEY, json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('3f9a', (string) $row['sealed_api_key']);
        self::assertSame('…3f9a', $row['masked_api_key']);

        $account = $this->settings()->accounts()[0];
        self::assertTrue($account->hasApiKey);
        self::assertSame('…3f9a', $account->maskedApiKey);
        self::assertStringNotContainsString(self::KEY, var_export($account, true));
        self::assertStringNotContainsString(self::KEY, var_export($this->settings()->views(), true));
        ob_start();
        var_dump($this->settings()->forRole(Role::Drawer));
        $dump = ob_get_clean().print_r($this->settings()->forRole(Role::Drawer), true);
        self::assertStringNotContainsString(self::KEY, $dump, 'The setup of a role hides its key from the dumps');
    }

    public function testAShortKeyShowsNoEnd(): void
    {
        self::assertSame('…', ModelSettings::mask('short'));
        self::assertSame('…3f9a', ModelSettings::mask(self::KEY));
    }

    public function testWithoutAnEncryptionKeyAKeyIsNotSavedAndNothingIsStored(): void
    {
        $encryption = new Encryption('');
        $settings = $this->settingsWith($encryption);

        try {
            $this->bothOn($settings, Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
            self::fail('A key cannot be saved without APP_ENCRYPTION_KEY');
        } catch (EncryptionException $exception) {
            self::assertStringContainsString('APP_ENCRYPTION_KEY', $exception->getMessage());
            self::assertStringContainsString('make install', $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
        self::assertFalse($this->settings()->isStored());

        // A server of one's own needs no key: it can be saved without one, and without APP_ENCRYPTION_KEY.
        $this->bothOn($settings, Provider::Compatible, 'http://gate:8001', 'qwen', 'qwen', true);
        self::assertTrue($this->settings()->isStored());
    }

    public function testAnotherEncryptionKeyCannotReadTheStoredKey(): void
    {
        $this->both(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
        $other = $this->settingsWith(new Encryption(base64_encode(str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));

        try {
            $other->forRole(Role::Drawer);
            self::fail('Another key cannot open the stored one');
        } catch (EncryptionException $exception) {
            self::assertStringContainsString('saved with another one', $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }

    public function testTheKeyIsKeptUntilTheProviderChanges(): void
    {
        $this->both(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);

        $this->both(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-haiku-5-5', true);
        self::assertSame(self::KEY, $this->settings()->forRole(Role::Drawer)->apiKey(), 'No key given: the one stored stays');
        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Drawer)->model);
        self::assertCount(1, self::getContainer()->get(ProviderAccountRepository::class)->findAll(), 'One account for one place');

        try {
            $this->both(Provider::OpenAi, null, 'gpt-4o', 'gpt-4o', false);
            self::fail('Another provider needs its own key');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('OpenAI needs an API key.', $exception->getMessage());
        }

        $this->both(Provider::Compatible, 'http://host.docker.internal:11434', 'qwen3', 'qwen3', true);
        $setup = $this->settings()->forRole(Role::Drawer);
        self::assertNull($setup->apiKey(), 'A key is for one provider: it does not follow to another');
        self::assertNull($this->settings()->account(Provider::Compatible, 'http://host.docker.internal:11434')?->maskedApiKey);
        self::assertSame('http://host.docker.internal:11434', $setup->provider->addressOr($setup->address));
        self::assertTrue($setup->reasoningOff);
    }

    public function testAnIncompleteSettingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the address');
        $this->both(Provider::Compatible, '  ', 'qwen', 'qwen', false);
    }

    public function testAnAccountStaysWhenNoRoleUsesItAnyMoreAndIsForgottenOnlyThen(): void
    {
        $this->both(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
        $anthropic = $this->settings()->accounts()[0];
        self::assertSame([Role::Drawer, Role::Guesser], $anthropic->usedBy);
        self::assertFalse($this->settings()->forget($anthropic->id), 'An account a role uses cannot be forgotten');

        // Both roles go to a server of one's own: the key of Anthropic stays, for a way back.
        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen', 'qwen', false);
        self::assertCount(2, $this->settings()->accounts());
        self::assertSame(self::KEY, $this->settings()->savedKeyFor(Provider::Anthropic, null));
        $this->both(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-sonnet-5-5', false);
        self::assertSame(self::KEY, $this->settings()->forRole(Role::Drawer)->apiKey(), 'Back on Anthropic without typing the key again');

        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen', 'qwen', false);
        self::assertTrue($this->settings()->forget($anthropic->id));
        self::assertNull($this->settings()->account(Provider::Anthropic, null));
        self::assertFalse($this->settings()->forget($anthropic->id), 'It is not there any more');
    }

    public function testTheDrawerCannotFollowAnotherRole(): void
    {
        $this->both(Provider::Compatible, 'http://gate:8001', 'qwen', 'qwen', false);

        $this->expectException(\InvalidArgumentException::class);
        $this->settings()->save([Role::Drawer->value => null]);
    }

    public function testTheMessagesThatDescribeTheModelNeverHoldTheKey(): void
    {
        $this->both(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);

        self::assertSame('claude-haiku-5-5 at Anthropic', $this->models()->describe(Role::Drawer));
        self::assertStringNotContainsString(self::KEY, $this->models()->fingerprint(Role::Drawer));
        $before = $this->models()->fingerprint(Role::Drawer);
        $this->both(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, 'sk-ant-api03-another-key-0000');
        self::assertNotSame($before, $this->models()->fingerprint(Role::Drawer), 'Another key is another check of the models');
    }

    private function settingsWith(Encryption $encryption): ModelSettings
    {
        $container = self::getContainer();

        return new ModelSettings(
            $container->get(ProviderAccountRepository::class),
            $container->get(RoleModelRepository::class),
            $container->get(EntityManagerInterface::class),
            $encryption,
        );
    }
}
