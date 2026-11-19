<?php

namespace App\Tests\Functional\Ai;

use App\Ai\Encryption;
use App\Ai\EncryptionException;
use App\Ai\Models;
use App\Ai\ModelSettings;
use App\Ai\PlatformFactory;
use App\Ai\Provider;
use App\Ai\Role;
use App\Repository\AiSettingRepository;
use App\Tests\Functional\RecordingPlatforms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The setting in the database, its API key sealed, and the game reading it before the environment.
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

    private function ask(Models $models, Role $role = Role::Drawer): void
    {
        $models->invoke($role, new MessageBag(Message::ofUser('hello')), maxTokens: 123)->asText();
    }

    public function testWithoutASettingTheGameReadsTheEnvironment(): void
    {
        self::assertFalse($this->settings()->isStored());
        self::assertNull($this->settings()->view());

        $setup = $this->settings()->forRole(Role::Drawer);
        self::assertSame(Provider::OwnServer, $setup->provider, 'The AI_* variables speak the API of OpenAI, on the Generic bridge');
        self::assertSame(self::getContainer()->getParameter('kernel.environment'), 'test');
        self::assertSame($_SERVER['AI_DRAWER_BASE_URL'] ?? $_ENV['AI_DRAWER_BASE_URL'] ?? 'http://host.docker.internal:11434', $setup->address);

        $this->ask($this->models());
        self::assertSame(Provider::OwnServer, $this->platforms->created[0][0]);
        // What the options were before the setting existed: the limit, and the reasoning switch of the variable.
        self::assertSame(123, $this->platforms->invoked[0][1]['max_output_tokens']);
        self::assertSame('none', $this->platforms->invoked[0][1]['reasoning_effort']);
    }

    public function testTheDatabaseWinsOverTheEnvironment(): void
    {
        $this->settings()->save(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-haiku-5-5', true, self::KEY);

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
        $this->settings()->save(Provider::Mistral, null, 'mistral-medium-3.5', 'mistral-small-latest', true, 'sk-mistral');
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123, 'chat_template_kwargs' => ['enable_thinking' => false], 'reasoning_effort' => 'none'], $this->platforms->invoked[0][1]);

        $this->settings()->save(Provider::Mistral, null, 'mistral-medium-3.5', 'mistral-small-latest', false);
        $this->ask($this->models());
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[1][1], 'Reasoning is left alone when the setting says so');

        // gpt-5.1 thinks (the catalog says so), gpt-4o does not.
        $this->settings()->save(Provider::OpenAi, null, 'gpt-5.1', 'gpt-4o', true, 'sk-openai');
        $this->ask($this->models(), Role::Drawer);
        $this->ask($this->models(), Role::Guesser);
        self::assertSame(['max_output_tokens' => 123, 'reasoning' => ['effort' => 'low']], $this->platforms->invoked[2][1]);
        self::assertSame(['max_output_tokens' => 123], $this->platforms->invoked[3][1]);
    }

    public function testTheKeyIsSealedInTheDatabaseAndTheScreenGetsItsEndOnly(): void
    {
        $this->settings()->save(Provider::OpenAi, null, 'gpt-4o', 'gpt-4o', false, self::KEY);

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM ai_setting');
        self::assertIsArray($row);
        self::assertStringNotContainsString(self::KEY, json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('3f9a', (string) $row['sealed_api_key']);
        self::assertSame('…3f9a', $row['masked_api_key']);

        $view = $this->settings()->view();
        self::assertNotNull($view);
        self::assertTrue($view->hasApiKey);
        self::assertSame('…3f9a', $view->maskedApiKey);
        self::assertStringNotContainsString(self::KEY, var_export($view, true));
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
            $settings->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
            self::fail('A key cannot be saved without APP_ENCRYPTION_KEY');
        } catch (EncryptionException $exception) {
            self::assertStringContainsString('APP_ENCRYPTION_KEY', $exception->getMessage());
            self::assertStringContainsString('make install', $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
        self::assertFalse($this->settings()->isStored());

        // A server of one's own needs no key: it can be saved without one, and without APP_ENCRYPTION_KEY.
        $settings->save(Provider::OwnServer, 'http://gate:8001', 'qwen', 'qwen', true);
        self::assertTrue($this->settings()->isStored());
    }

    public function testAnotherEncryptionKeyCannotReadTheStoredKey(): void
    {
        $this->settings()->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
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
        $this->settings()->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);

        $this->settings()->save(Provider::Anthropic, null, 'claude-sonnet-5-5', 'claude-haiku-5-5', true);
        self::assertSame(self::KEY, $this->settings()->forRole(Role::Drawer)->apiKey(), 'No key given: the one stored stays');
        self::assertSame('claude-sonnet-5-5', $this->settings()->forRole(Role::Drawer)->model);
        self::assertCount(1, self::getContainer()->get(AiSettingRepository::class)->findAll(), 'One setting for the whole installation');

        try {
            $this->settings()->save(Provider::OpenAi, null, 'gpt-4o', 'gpt-4o', false);
            self::fail('Another provider needs its own key');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('OpenAI needs an API key.', $exception->getMessage());
        }

        $this->settings()->save(Provider::Ollama, null, 'qwen3', 'qwen3', true);
        $setup = $this->settings()->forRole(Role::Drawer);
        self::assertNull($setup->apiKey(), 'A key is for one provider: it does not follow to another');
        self::assertNull($this->settings()->view()?->maskedApiKey);
        self::assertSame('http://host.docker.internal:11434', $setup->provider->addressOr($setup->address));
    }

    public function testAnIncompleteSettingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the address');
        $this->settings()->save(Provider::OwnServer, '  ', 'qwen', 'qwen', false);
    }

    public function testRemovingTheSettingGivesTheEnvironmentBack(): void
    {
        $this->settings()->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);
        self::assertSame(Provider::Anthropic, $this->settings()->forRole(Role::Guesser)->provider);

        $this->settings()->remove();

        self::assertFalse($this->settings()->isStored());
        self::assertSame(Provider::OwnServer, $this->settings()->forRole(Role::Guesser)->provider);
    }

    public function testTheMessagesThatDescribeTheModelNeverHoldTheKey(): void
    {
        $this->settings()->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, self::KEY);

        self::assertSame('claude-haiku-5-5 at Anthropic', $this->models()->describe(Role::Drawer));
        self::assertStringNotContainsString(self::KEY, $this->models()->fingerprint(Role::Drawer));
        $before = $this->models()->fingerprint(Role::Drawer);
        $this->settings()->save(Provider::Anthropic, null, 'claude-haiku-5-5', 'claude-haiku-5-5', false, 'sk-ant-api03-another-key-0000');
        self::assertNotSame($before, $this->models()->fingerprint(Role::Drawer), 'Another key is another check of the models');
    }

    private function settingsWith(Encryption $encryption): ModelSettings
    {
        $container = self::getContainer();

        return new ModelSettings(
            $container->get(AiSettingRepository::class),
            $container->get(EntityManagerInterface::class),
            $encryption,
            'http://env:1', 'env-model', '', true,
            'http://env:1', 'env-model', '', true,
        );
    }
}
