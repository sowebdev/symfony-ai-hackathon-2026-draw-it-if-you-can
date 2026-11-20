<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelChoice;
use App\Ai\ModelsDev;
use App\Ai\Provider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Capability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * On tests/Fixtures/models-dev.json (MODELS_DEV_FILE in .env.test), a few real entries of models.dev.
 */
final class ModelsDevTest extends TestCase
{
    private const array ANTHROPIC = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-2', 'claude-haiku-4-5-20251001'];

    protected function tearDown(): void
    {
        unset($_SERVER['MODELS_DEV_FILE']);
        ModelsDev::forget();
    }

    public function testTheMiddleOfTheRangeIsNeitherTheCheapestNorTheDearest(): void
    {
        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-2'], ModelsDev::range(Provider::Anthropic, self::ANTHROPIC));
        self::assertSame('claude-sonnet-5-5', ModelsDev::recommended(Provider::Anthropic, self::ANTHROPIC));
        self::assertSame('gpt-6.2-sol', ModelsDev::recommended(Provider::OpenAi, ['gpt-4o', 'gpt-5.1', 'gpt-6-luna', 'gpt-6.2-sol', 'gpt-6.2-astra']));
    }

    public function testOnlyWhatTheProviderListsCounts(): void
    {
        self::assertSame('claude-haiku-5-5', ModelsDev::recommended(Provider::Anthropic, ['claude-haiku-5-5', 'claude-sonnet-5-5']), 'Of two, the cheaper');
        self::assertNull(ModelsDev::recommended(Provider::Anthropic, ['claude-not-yet-released']));
        self::assertNull(ModelsDev::recommended(Provider::Compatible, ['qwen3.8:27b']), 'A server of one\'s own: models.dev does not know its names');
    }

    public function testAnOlderModelOfAFamilyIsNotItsCard(): void
    {
        $arranged = ModelsDev::arrange(Provider::Anthropic, array_map(static fn (string $name): ModelChoice => new ModelChoice($name, true, true), [...self::ANTHROPIC, 'claude-brand-new-9']));

        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-2'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['cards']));
        self::assertSame(['claude-brand-new-9', 'claude-haiku-4-5-20251001'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['others']));
    }

    public function testTheCatalogOfModelsDevUsesTheModelClassOfEachBridge(): void
    {
        self::assertInstanceOf(Claude::class, ModelsDev::catalog(Provider::Anthropic)?->getModel('claude-fable-5-2'));
        self::assertInstanceOf(Gpt::class, ModelsDev::catalog(Provider::OpenAi)?->getModel('gpt-6.2-sol'));
        self::assertInstanceOf(Mistral::class, ModelsDev::catalog(Provider::Mistral)?->getModel('mistral-small-latest'));
        self::assertTrue(Provider::Anthropic->knows('claude-fable-5-2')?->supports(Capability::INPUT_IMAGE), 'Through the catalog of the provider');
        self::assertNull(ModelsDev::catalog(Provider::Compatible));
    }

    public function testWithoutTheFileTheBridgesPlayAlone(): void
    {
        $_SERVER['MODELS_DEV_FILE'] = '/nonexistent/models-dev.json';
        ModelsDev::forget();

        self::assertNull(ModelsDev::catalog(Provider::Anthropic));
        self::assertNull(ModelsDev::recommended(Provider::Anthropic, self::ANTHROPIC));
        self::assertNull(Provider::Anthropic->knows('claude-fable-5-2'), 'Not in the catalog of the bridge');
        self::assertTrue(Provider::Anthropic->knows('claude-haiku-5-5')?->supports(Capability::TOOL_CALLING));
    }

    public function testDownloadsOnlyTheGameProvidersAndOnlyWhenTheFileIsMissing(): void
    {
        $_SERVER['MODELS_DEV_FILE'] = sys_get_temp_dir().'/models-dev-'.bin2hex(random_bytes(4)).'.json';
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode([
                'anthropic' => ['id' => 'anthropic', 'models' => ['claude-haiku-5-5' => ['id' => 'claude-haiku-5-5']]],
                'groq' => ['id' => 'groq', 'models' => ['llama' => ['id' => 'llama']]],
            ], \JSON_THROW_ON_ERROR));
        });
        try {
            (new ModelsDev($http))->ensure();
            (new ModelsDev($http))->ensure();

            self::assertSame(1, $calls);
            self::assertSame(['anthropic'], array_keys(json_decode((string) file_get_contents($_SERVER['MODELS_DEV_FILE']), true)));
        } finally {
            @unlink($_SERVER['MODELS_DEV_FILE']);
        }
    }

    public function testAFailedDownloadIsSilent(): void
    {
        $_SERVER['MODELS_DEV_FILE'] = '/nonexistent/models-dev.json';

        (new ModelsDev(new MockHttpClient(new MockResponse('', ['http_code' => 503]))))->ensure();

        self::assertFileDoesNotExist('/nonexistent/models-dev.json');
    }
}
