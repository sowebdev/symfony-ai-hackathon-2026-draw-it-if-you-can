<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelList;
use App\Ai\ModelLister;
use App\Ai\Provider;
use App\Ai\Role;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The list of the models, on recorded answers: no provider is called.
 */
final class ModelListerTest extends TestCase
{
    /** @var list<array{string, string, array<string, string>}> */
    private array $requests = [];

    /**
     * @param \Closure(string, string, array<string, mixed>): MockResponse $answer
     */
    private function lister(\Closure $answer, ?ArrayAdapter $cache = null): ModelLister
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->requests[] = [$method, $url, $headers];

            return $answer($method, $url, $options);
        });

        return new ModelLister($http, $cache ?? new ArrayAdapter());
    }

    private function sent(): int
    {
        return \count($this->requests);
    }

    private static function json(mixed $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param list<\App\Ai\ModelChoice> $choices
     *
     * @return list<string>
     */
    private static function names(array $choices): array
    {
        return array_map(static fn (\App\Ai\ModelChoice $choice): string => $choice->name, $choices);
    }

    public function testAnthropicPagesAndOffersOnlyWhatTheCatalogSaysCanPlay(): void
    {
        $lister = $this->lister(static fn (string $method, string $url): MockResponse => str_contains($url, 'after_id=claude-3-opus-20240229')
            ? self::json(['data' => [['id' => 'claude-haiku-5-5'], ['id' => 'claude-brand-new-9']], 'has_more' => false, 'last_id' => 'claude-brand-new-9'])
            : self::json(['data' => [['id' => 'claude-sonnet-5-5'], ['id' => 'claude-3-opus-20240229']], 'has_more' => true, 'last_id' => 'claude-3-opus-20240229']));

        $list = $lister->list(Provider::Anthropic, null, 'sk-ant-secret');

        self::assertTrue($list->isRead());
        // Sorted by name; a model the catalog does not know is not listed: its name is typed.
        self::assertSame(['claude-3-opus-20240229', 'claude-haiku-5-5', 'claude-sonnet-5-5'], self::names($list->for(Role::Guesser)));
        self::assertSame(['claude-3-opus-20240229', 'claude-haiku-5-5', 'claude-sonnet-5-5'], self::names($list->for(Role::Drawer)));
        self::assertNotContains('claude-brand-new-9', self::names($list->choices));
        self::assertTrue($list->choices[0]->vision);
        self::assertTrue($list->choices[0]->tools);

        self::assertCount(2, $this->requests);
        [$method, $url, $headers] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertStringStartsWith('https://api.anthropic.com/v1/models?', $url);
        self::assertSame('sk-ant-secret', $headers['x-api-key']);
        self::assertSame('2023-06-01', $headers['anthropic-version']);
        self::assertStringContainsString('after_id=claude-3-opus-20240229', $this->requests[1][1]);
    }

    public function testOpenAiKeepsEachRoleToWhatTheCatalogSaysItCanDo(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['object' => 'list', 'data' => [
            ['id' => 'gpt-4o'],        // reads images, calls tools
            ['id' => 'gpt-3.5-turbo'], // calls tools, no images: neither role
            ['id' => 'text-embedding-3-small'],
            ['id' => 'whisper-1'],
            ['id' => 'gpt-5-snapshot-not-in-the-catalog'],
        ]]));

        $list = $lister->list(Provider::OpenAi, null, 'sk-openai');

        self::assertSame(['gpt-4o'], self::names($list->for(Role::Guesser)));
        self::assertSame(['gpt-4o'], self::names($list->for(Role::Drawer)));
        self::assertSame(['gpt-4o'], self::names($list->choices), 'Embeddings, audio, a model without images and one the catalog does not know are not listed');
        self::assertSame('https://api.openai.com/v1/models', $this->requests[0][1]);
        self::assertSame('Bearer sk-openai', $this->requests[0][2]['authorization']);
    }

    public function testAModelWithoutToolsCanGuessButNotDraw(): void
    {
        // Straight from the catalogs of the bridges: no model of the list is invented.
        $names = array_keys(Provider::OpenAi->bridgeCatalog()?->getModels() ?? []);
        $guessOnly = array_values(array_filter($names, static function (string $name): bool {
            $capabilities = Provider::OpenAi->bridgeCatalog()?->getModels()[$name]['capabilities'] ?? [];

            return \in_array(Capability::INPUT_IMAGE, $capabilities, true) && !\in_array(Capability::TOOL_CALLING, $capabilities, true);
        }));
        self::assertNotSame([], $guessOnly, 'The catalog has a model that reads images and calls no tool');

        $list = new ModelList(ModelLister::choices(Provider::OpenAi, [['id' => $guessOnly[0]]]));

        self::assertSame([$guessOnly[0]], self::names($list->for(Role::Guesser)));
        self::assertSame([], $list->for(Role::Drawer));
    }

    public function testMistralOwnCapabilitiesDecideWhenTheyAreThere(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [
            ['id' => 'mistral-small-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => true, 'vision' => true]],
            ['id' => 'ministral-8b-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => true, 'vision' => false]],
            ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false, 'function_calling' => false, 'vision' => false]],
            // The answer says it sees images: the catalog of the bridge, which does not say so, is not asked.
            ['id' => 'codestral-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => false, 'vision' => true]],
            // No capabilities in the answer: the catalog of the bridge decides.
            ['id' => 'pixtral-large-latest'],
            ['id' => 'mistral-nemo'],
            ['id' => 'a-model-nobody-knows'],
        ]]));

        $list = $lister->list(Provider::Mistral, null, 'sk-mistral');

        self::assertSame(['codestral-latest', 'mistral-small-latest', 'pixtral-large-latest'], self::names($list->for(Role::Guesser)));
        self::assertSame(['mistral-small-latest', 'pixtral-large-latest'], self::names($list->for(Role::Drawer)));
        self::assertNotContains('mistral-embed', self::names($list->choices));
        self::assertNotContains('a-model-nobody-knows', self::names($list->choices));
        self::assertSame('https://api.mistral.ai/v1/models', $this->requests[0][1]);
        self::assertSame('Bearer sk-mistral', $this->requests[0][2]['authorization']);
    }

    public function testAnotherServerWithoutCapabilitiesIsListedAsItIsSortedByName(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['object' => 'list', 'data' => [['id' => 'qwen3.8:27b'], ['id' => 'Lfm3-vl:2b'], ['id' => 'nomic-embed-text']]]));

        $list = $lister->list(Provider::Compatible, 'http://gate:8001/', null);

        self::assertSame(['Lfm3-vl:2b', 'nomic-embed-text', 'qwen3.8:27b'], self::names($list->for(Role::Drawer)));
        self::assertFalse($list->hasCapabilities());
        self::assertNull($list->choices[0]->vision, 'Capabilities unknown: the check will tell');
        self::assertSame('http://gate:8001/v1/models', $this->requests[0][1]);
        self::assertArrayNotHasKey('authorization', $this->requests[0][2]);
    }

    public function testAnApiKeyIsSentToAnotherServerWhenThereIsOne(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [['id' => 'qwen']]]));

        $lister->list(Provider::Compatible, 'https://openrouter.ai/api', 'sk-or-secret');

        self::assertSame('https://openrouter.ai/api/v1/models', $this->requests[0][1]);
        self::assertSame('Bearer sk-or-secret', $this->requests[0][2]['authorization']);
    }

    public function testOpenRouterSaysWhatItsModelsCanDoAndTheListIsFilteredOnIt(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [
            ['id' => 'anthropic/claude-haiku', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'supported_parameters' => ['tools', 'max_tokens']],
            ['id' => 'meta/text-only', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']], 'supported_parameters' => ['tools']],
            ['id' => 'acme/sees-no-tools', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'supported_parameters' => ['max_tokens']],
            ['id' => 'openai/embedding', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['embeddings']], 'supported_parameters' => []],
            ['id' => 'acme/image-maker', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['image']], 'supported_parameters' => []],
            ['id' => 'acme/says-nothing'],
        ]]));

        $list = $lister->list(Provider::Compatible, 'https://openrouter.ai/api', 'sk-or');

        self::assertTrue($list->hasCapabilities());
        self::assertSame(['acme/says-nothing', 'acme/sees-no-tools', 'anthropic/claude-haiku'], self::names($list->for(Role::Guesser)));
        self::assertSame(['acme/says-nothing', 'anthropic/claude-haiku'], self::names($list->for(Role::Drawer)));
        self::assertNotContains('meta/text-only', self::names($list->for(Role::Guesser)));
        self::assertNotContains('acme/image-maker', self::names($list->choices));
        self::assertNotContains('openai/embedding', self::names($list->choices));
    }

    public function testAHugeListIsSortedAndComplete(): void
    {
        $entries = array_map(static fn (int $i): array => ['id' => \sprintf('vendor/model-%03d', 400 - $i)], range(1, 400));
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => $entries]));

        $names = self::names($lister->list(Provider::Compatible, 'https://openrouter.ai/api', 'sk')->for(Role::Guesser));

        self::assertCount(400, $names);
        self::assertSame('vendor/model-000', $names[0]);
        self::assertSame('vendor/model-399', $names[399]);
    }

    public function testARefusedKeyIsSaidWithoutTheKey(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['error' => ['message' => 'invalid x-api-key sk-ant-secret']], 401));

        $list = $lister->list(Provider::Anthropic, null, 'sk-ant-secret');

        self::assertFalse($list->isRead());
        self::assertSame('list.refused_key', $list->problem?->id);
        self::assertSame(['%provider%' => 'Anthropic', '%status%' => '401'], $list->problem->parameters);
        self::assertStringNotContainsString('sk-ant-secret', serialize($list->problem));
        self::assertSame([], $list->choices);
    }

    public function testAServerThatDoesNotAnswerIsSaid(): void
    {
        $lister = $this->lister(static fn (): MockResponse => throw new TransportException('Failed to connect'));

        $list = $lister->list(Provider::Compatible, 'http://gate:8001', null);

        self::assertSame('list.no_answer', $list->problem?->id);
        self::assertSame(['%where%' => 'http://gate:8001'], $list->problem->parameters);
    }

    public function testASlowServerIsSaid(): void
    {
        // The mock cannot be slow: its timeout is an exception of the same family.
        $lister = $this->lister(static fn (): MockResponse => throw new TimeoutException('Idle timeout reached'));

        $problem = $lister->list(Provider::Compatible, Provider::SUGGESTED_ADDRESS, null)->problem;

        self::assertSame('list.timeout', $problem?->id);
        self::assertSame('3', $problem->parameters['%seconds%']);
    }

    public function testTheRequestsAreGivenTheirTimeouts(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return self::json(['data' => []]);
        });

        (new ModelLister($http, new ArrayAdapter()))->list(Provider::Compatible, 'http://gate:8001', null);

        self::assertEquals(3, $seen['timeout']);
        self::assertGreaterThan(5.0, $seen['max_duration']);
        self::assertLessThanOrEqual(6.0, $seen['max_duration']);
    }

    public function testAnAnswerThatIsNotAListIsSaid(): void
    {
        $lister = $this->lister(static fn (): MockResponse => new MockResponse('<html>not json</html>'));

        self::assertSame('list.not_a_list', $lister->list(Provider::Mistral, null, 'sk')->problem?->id);
    }

    public function testAProviderThatNeedsAKeyOrAnAddressSaysSoWithoutCallingIt(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => []]));

        self::assertSame('list.needs_key', $lister->list(Provider::OpenAi, null, '')->problem?->id);
        self::assertSame('list.needs_address', $lister->list(Provider::Compatible, null, null)->problem?->id);
        self::assertSame([], $this->requests);
    }

    public function testTheListIsKeptForAnHourInTheCloudAndThirtySecondsOnAServer(): void
    {
        self::assertSame(3600, Provider::Anthropic->listTtl());
        self::assertSame(3600, Provider::OpenAi->listTtl());
        self::assertSame(3600, Provider::Mistral->listTtl());
        self::assertSame(30, Provider::Compatible->listTtl());
    }

    public function testTheListIsCachedPerKeyAndTheCacheNeverHoldsTheKey(): void
    {
        $cache = new ArrayAdapter();
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [['id' => 'mistral-small-latest', 'capabilities' => ['vision' => true, 'function_calling' => true]]]]), $cache);

        $lister->list(Provider::Mistral, null, 'sk-mistral-secret');
        $lister->list(Provider::Mistral, null, 'sk-mistral-secret');
        self::assertSame(1, $this->sent(), 'The second list comes from the cache');

        $lister->list(Provider::Mistral, null, 'sk-another');
        self::assertSame(2, $this->sent(), 'Another key is another list');

        $lister->list(Provider::Mistral, null, 'sk-mistral-secret', fresh: true);
        self::assertSame(3, $this->sent(), 'A fresh list is asked again');

        foreach (array_keys($cache->getValues()) as $key) {
            self::assertStringNotContainsString('sk-mistral-secret', $key);
        }
        self::assertStringNotContainsString('sk-mistral-secret', serialize($cache->getValues()));
    }

    public function testAFailureIsNotKept(): void
    {
        $calls = 0;
        $lister = $this->lister(static function () use (&$calls): MockResponse {
            return 1 === ++$calls ? self::json([], 500) : self::json(['data' => [['id' => 'qwen']]]);
        });

        $first = $lister->list(Provider::Compatible, Provider::SUGGESTED_ADDRESS, null)->problem;
        self::assertSame('list.http_error', $first?->id);
        self::assertSame('500', $first->parameters['%status%']);
        self::assertSame(['qwen'], self::names($lister->list(Provider::Compatible, Provider::SUGGESTED_ADDRESS, null)->choices));
    }
}
