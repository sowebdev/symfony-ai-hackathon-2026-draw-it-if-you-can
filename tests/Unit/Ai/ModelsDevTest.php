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

    /** @var array{?string, ?string} MODELS_DEV_FILE of the test environment, in and */
    private array $file;

    protected function setUp(): void
    {
        $this->file = [$_SERVER['MODELS_DEV_FILE'] ?? null, $_ENV['MODELS_DEV_FILE'] ?? null];
    }

    protected function tearDown(): void
    {
        [$_SERVER['MODELS_DEV_FILE'], $_ENV['MODELS_DEV_FILE']] = $this->file;
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

    public function testTheCardsAreTheCurrentRangeWithoutModelsForAnotherUse(): void
    {
        $names = ['gpt-4o', 'gpt-5.4-nano', 'gpt-6-luna', 'gpt-6.2-sol', 'gpt-realtime-2.1', 'gpt-6.2-astra'];
        $arranged = ModelsDev::arrange(Provider::OpenAi, array_map(static fn (string $name): ModelChoice => new ModelChoice($name, true, true), $names));

        // gpt-realtime-2.1 says `type: realtime`; gpt-4o is more than a year behind gpt-6.2-astra.
        self::assertSame(['gpt-6-luna', 'gpt-5.4-nano', 'gpt-6.2-sol', 'gpt-6.2-astra'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['cards']));
        self::assertSame(['gpt-4o', 'gpt-realtime-2.1'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['others']));
        self::assertSame('gpt-5.4-nano', ModelsDev::recommended(Provider::OpenAi, $names), 'The cheaper of the two middles, the one preselected is a card');
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

    public function testTheUpdateOfTheInstallationWinsOverTheVersionedExtract(): void
    {
        unset($_SERVER['MODELS_DEV_FILE'], $_ENV['MODELS_DEV_FILE']);
        $root = sys_get_temp_dir().'/models-dev-'.bin2hex(random_bytes(4));
        mkdir($root.'/var', 0o777, true);
        try {
            self::assertSame($root.'/data/models-dev.json', ModelsDev::path($root), 'A fresh clone reads the versioned extract');
            self::assertSame($root.'/var/models-dev.json', ModelsDev::updatedPath($root), 'An update never writes the versioned extract');
            touch($root.'/var/models-dev.json');
            self::assertSame($root.'/var/models-dev.json', ModelsDev::path($root));
        } finally {
            @unlink($root.'/var/models-dev.json');
            rmdir($root.'/var');
            rmdir($root);
        }
    }

    public function testTheGameShipsAnExtractOfItsThreeProviders(): void
    {
        $extract = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/data/models-dev.json'), true);

        self::assertIsArray($extract);
        self::assertSame(['anthropic', 'openai', 'mistral'], array_keys($extract));
    }

    public function testAnUpdateKeepsOnlyTheGameProviders(): void
    {
        $_SERVER['MODELS_DEV_FILE'] = sys_get_temp_dir().'/models-dev-'.bin2hex(random_bytes(4)).'.json';
        $http = new MockHttpClient(new MockResponse(json_encode([
            'anthropic' => ['id' => 'anthropic', 'models' => ['claude-haiku-5-5' => ['id' => 'claude-haiku-5-5']]],
            'groq' => ['id' => 'groq', 'models' => ['llama' => ['id' => 'llama']]],
        ], \JSON_THROW_ON_ERROR)));
        try {
            self::assertSame(1, (new ModelsDev($http))->update());
            self::assertSame(['anthropic'], array_keys(json_decode((string) file_get_contents($_SERVER['MODELS_DEV_FILE']), true)));
        } finally {
            @unlink($_SERVER['MODELS_DEV_FILE']);
        }
    }

    public function testAFailedUpdateLeavesTheFileAsItWas(): void
    {
        $_SERVER['MODELS_DEV_FILE'] = '/nonexistent/models-dev.json';

        try {
            (new ModelsDev(new MockHttpClient(new MockResponse('', ['http_code' => 503]))))->update();
            self::fail('No exception');
        } catch (\Throwable) {
        }
        self::assertFileDoesNotExist('/nonexistent/models-dev.json');
    }
}
