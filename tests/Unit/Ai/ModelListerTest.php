<?php

namespace App\Tests\Unit\Ai;

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

    public function testAnthropicPagesAndIsCrossedWithTheCatalog(): void
    {
        $lister = $this->lister(static fn (string $method, string $url): MockResponse => str_contains($url, 'after_id=claude-3-opus-20240229')
            ? self::json(['data' => [['id' => 'claude-haiku-5-5'], ['id' => 'claude-brand-new-9']], 'has_more' => false, 'last_id' => 'claude-brand-new-9'])
            : self::json(['data' => [['id' => 'claude-sonnet-5-5'], ['id' => 'claude-3-opus-20240229']], 'has_more' => true, 'last_id' => 'claude-3-opus-20240229']));

        $list = $lister->list(Provider::Anthropic, null, 'sk-ant-secret');

        self::assertTrue($list->isRead());
        self::assertSame(['claude-sonnet-5-5', 'claude-3-opus-20240229', 'claude-haiku-5-5'], $list->guesser);
        self::assertSame(['claude-sonnet-5-5', 'claude-3-opus-20240229', 'claude-haiku-5-5'], $list->drawer);
        self::assertSame(['claude-brand-new-9'], $list->others, 'A model the catalog does not know stays usable, untested');

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
            ['id' => 'gpt-5-snapshot-not-in-the-catalog'],
        ]]));

        $list = $lister->list(Provider::OpenAi, null, 'sk-openai');

        self::assertSame(['gpt-4o'], $list->guesser);
        self::assertSame(['gpt-4o'], $list->drawer);
        self::assertSame(['gpt-5-snapshot-not-in-the-catalog'], $list->others);
        self::assertSame(['gpt-4o'], $list->knownFor(Role::Drawer));
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

        $list = ModelLister::cross(Provider::OpenAi, [$guessOnly[0]]);

        self::assertSame([$guessOnly[0]], $list->guesser);
        self::assertSame([], $list->drawer);
        self::assertSame([], $list->others);
    }

    public function testAGenericProviderHasNoCatalog(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [['id' => 'mistral-small-latest'], ['id' => 'pixtral-large-latest']]]));

        $list = $lister->list(Provider::Mistral, null, 'sk-mistral');

        self::assertSame([], $list->guesser);
        self::assertSame([], $list->drawer);
        self::assertSame(['mistral-small-latest', 'pixtral-large-latest'], $list->others);
        self::assertSame('https://api.mistral.ai/v1/models', $this->requests[0][1]);
        self::assertSame('Bearer sk-mistral', $this->requests[0][2]['authorization']);
    }

    public function testOllamaAndAServerOfYourOwnAreAskedAtTheirAddressWithoutKey(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['object' => 'list', 'data' => [['id' => 'qwen3.8:27b'], ['id' => 'lfm3-vl:2b']]]));

        $ollama = $lister->list(Provider::Ollama, null, null);
        $own = $lister->list(Provider::OwnServer, 'http://gate:8001/', null);

        self::assertSame(['qwen3.8:27b', 'lfm3-vl:2b'], $ollama->others);
        self::assertSame(['qwen3.8:27b', 'lfm3-vl:2b'], $own->others);
        self::assertSame('http://host.docker.internal:11434/v1/models', $this->requests[0][1]);
        self::assertSame('http://gate:8001/v1/models', $this->requests[1][1]);
        self::assertArrayNotHasKey('authorization', $this->requests[0][2]);
    }

    public function testARefusedKeyIsSaidWithoutTheKey(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['error' => ['message' => 'invalid x-api-key sk-ant-secret']], 401));

        $list = $lister->list(Provider::Anthropic, null, 'sk-ant-secret');

        self::assertFalse($list->isRead());
        self::assertStringContainsString('refused the API key (HTTP 401)', (string) $list->problem);
        self::assertStringNotContainsString('sk-ant-secret', (string) $list->problem);
        self::assertSame([], $list->others);
    }

    public function testAServerThatDoesNotAnswerIsSaid(): void
    {
        $lister = $this->lister(static fn (): MockResponse => throw new TransportException('Failed to connect'));

        $list = $lister->list(Provider::OwnServer, 'http://gate:8001', null);

        self::assertSame('Nothing answers at http://gate:8001: is the address right, is the server started?', $list->problem);
    }

    public function testASlowServerIsSaid(): void
    {
        // The mock cannot be slow: its timeout is an exception of the same family.
        $lister = $this->lister(static fn (): MockResponse => throw new TimeoutException('Idle timeout reached'));

        self::assertSame('Ollama did not answer within 3 s.', $lister->list(Provider::Ollama, null, null)->problem);
    }

    public function testTheRequestsAreGivenTheirTimeouts(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return self::json(['data' => []]);
        });

        (new ModelLister($http, new ArrayAdapter()))->list(Provider::OwnServer, 'http://gate:8001', null);

        self::assertEquals(3, $seen['timeout']);
        self::assertGreaterThan(5.0, $seen['max_duration']);
        self::assertLessThanOrEqual(6.0, $seen['max_duration']);
    }

    public function testAnAnswerThatIsNotAListIsSaid(): void
    {
        $lister = $this->lister(static fn (): MockResponse => new MockResponse('<html>not json</html>'));

        self::assertSame('Mistral did not answer with a list of models.', $lister->list(Provider::Mistral, null, 'sk')->problem);
    }

    public function testAProviderThatNeedsAKeyOrAnAddressSaysSoWithoutCallingIt(): void
    {
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => []]));

        self::assertStringContainsString('needs an API key', (string) $lister->list(Provider::OpenAi, null, '')->problem);
        self::assertStringContainsString('needs the address', (string) $lister->list(Provider::OwnServer, null, null)->problem);
        self::assertSame([], $this->requests);
    }

    public function testTheListIsKeptForAnHourInTheCloudAndThirtySecondsOnAServer(): void
    {
        self::assertSame(3600, Provider::Anthropic->listTtl());
        self::assertSame(3600, Provider::OpenAi->listTtl());
        self::assertSame(3600, Provider::Mistral->listTtl());
        self::assertSame(30, Provider::Ollama->listTtl());
        self::assertSame(30, Provider::OwnServer->listTtl());
    }

    public function testTheListIsCachedPerKeyAndTheCacheNeverHoldsTheKey(): void
    {
        $cache = new ArrayAdapter();
        $lister = $this->lister(static fn (): MockResponse => self::json(['data' => [['id' => 'mistral-small-latest']]]), $cache);

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

        self::assertStringContainsString('HTTP 500', (string) $lister->list(Provider::Ollama, null, null)->problem);
        self::assertSame(['qwen'], $lister->list(Provider::Ollama, null, null)->others);
    }
}
