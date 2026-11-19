<?php

namespace App\Tests\Unit\Ai;

use App\Ai\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderTest extends TestCase
{
    public function testWhatEachProviderIsAndNeeds(): void
    {
        self::assertNull(Provider::Anthropic->defaultAddress());
        self::assertNull(Provider::OpenAi->defaultAddress());
        self::assertSame('https://api.mistral.ai', Provider::Mistral->defaultAddress());
        self::assertSame('http://host.docker.internal:11434', Provider::Ollama->defaultAddress());
        self::assertNull(Provider::OwnServer->defaultAddress());

        self::assertSame([true, true, true, false, false], array_map(static fn (Provider $p): bool => $p->needsKey(), Provider::cases()));
        // Anthropic and OpenAI are always at the same place; the others have an address to set.
        self::assertSame([false, false, true, true, true], array_map(static fn (Provider $p): bool => $p->hasAddress(), Provider::cases()));
        // Only the two dedicated bridges; Ollama stays on the Generic one (ADR 0004).
        self::assertSame([false, false, true, true, true], array_map(static fn (Provider $p): bool => $p->isGeneric(), Provider::cases()));
        foreach (Provider::cases() as $provider) {
            self::assertNotSame('', $provider->label());
        }
    }

    public function testTheLimitIsTheCanonicalOptionOfEveryBridge(): void
    {
        foreach (Provider::cases() as $provider) {
            self::assertSame([PlatformOptions::MAX_OUTPUT_TOKENS => 1000], $provider->limit(1000));
        }
    }

    /**
     * @return iterable<string, array{Provider, bool, array<string, mixed>}>
     */
    public static function reasoningOptions(): iterable
    {
        $generic = ['chat_template_kwargs' => ['enable_thinking' => false], 'reasoning_effort' => 'none'];
        yield 'Mistral keeps what the game sent so far' => [Provider::Mistral, false, $generic];
        yield 'Ollama keeps it' => [Provider::Ollama, true, $generic];
        yield 'an own server keeps it' => [Provider::OwnServer, false, $generic];
        yield 'Anthropic sends nothing: its API would answer 400' => [Provider::Anthropic, true, []];
        yield 'OpenAI sends nothing for a model that does not think' => [Provider::OpenAi, false, []];
        yield 'OpenAI asks the least effort of a model that thinks' => [Provider::OpenAi, true, ['reasoning' => ['effort' => 'low']]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('reasoningOptions')]
    public function testTurningReasoningOff(Provider $provider, bool $thinks, array $expected): void
    {
        self::assertSame($expected, $provider->reasoningOff($thinks));
    }

    public function testTheCatalogKnowsWhatABridgeKnows(): void
    {
        self::assertNull(Provider::Mistral->bridgeCatalog());
        self::assertNull(Provider::Ollama->knows('qwen3'));
        self::assertTrue(Provider::Anthropic->knows('claude-haiku-5-5')?->supports(\Symfony\AI\Platform\Capability::TOOL_CALLING));
        self::assertNull(Provider::Anthropic->knows('claude-not-yet-released'));
    }

    /**
     * What each platform really sends, with no network: the address, the way the key is given and the
     * body, where the limit and the reasoning are said as the API of the provider takes them.
     *
     * @return iterable<string, array{Provider, ?string, non-empty-string, string, array<string, mixed>, list<string>, list<string>, string}>
     */
    public static function requests(): iterable
    {
        $generic = '{"choices":[{"message":{"role":"assistant","content":"ok"},"finish_reason":"stop"}]}';
        yield 'Anthropic' => [
            Provider::Anthropic, null, 'claude-haiku-5-5', 'POST https://api.anthropic.com/v1/messages',
            ['max_tokens' => 50], ['x-api-key: sk-test'], ['reasoning_effort', 'chat_template_kwargs', 'max_output_tokens'],
            '{"id":"msg_1","type":"message","role":"assistant","model":"claude-haiku-5-5","content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}',
        ];
        yield 'Anthropic, a model the catalog does not know' => [
            Provider::Anthropic, null, 'claude-not-yet-released', 'POST https://api.anthropic.com/v1/messages',
            ['max_tokens' => 50, 'model' => 'claude-not-yet-released'], ['x-api-key: sk-test'], ['reasoning_effort'],
            '{"id":"msg_1","type":"message","role":"assistant","content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}',
        ];
        yield 'OpenAI' => [
            Provider::OpenAi, null, 'gpt-4o', 'POST https://api.openai.com/v1/responses',
            ['max_output_tokens' => 50], ['authorization: Bearer sk-test'], ['max_tokens', 'reasoning_effort', 'chat_template_kwargs'],
            '{"id":"resp_1","object":"response","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"ok"}]}],"usage":{"input_tokens":1,"output_tokens":1,"total_tokens":2}}',
        ];
        yield 'Mistral' => [
            Provider::Mistral, null, 'mistral-small-latest', 'POST https://api.mistral.ai/v1/chat/completions',
            ['max_tokens' => 50, 'reasoning_effort' => 'none'], ['authorization: Bearer sk-test'], ['max_output_tokens'], $generic,
        ];
        yield 'Ollama' => [
            Provider::Ollama, null, 'qwen3:8b', 'POST http://host.docker.internal:11434/v1/chat/completions',
            ['max_tokens' => 50, 'reasoning_effort' => 'none', 'chat_template_kwargs' => ['enable_thinking' => false]], [], ['max_output_tokens'], $generic,
        ];
        yield 'an own server, with a slash at the end of its address' => [
            Provider::OwnServer, 'http://gate:8001/', 'qwen', 'POST http://gate:8001/v1/chat/completions',
            ['max_tokens' => 50, 'reasoning_effort' => 'none'], ['authorization: Bearer sk-test'], ['max_output_tokens'], $generic,
        ];
    }

    /**
     * @param non-empty-string     $model
     * @param array<string, mixed> $bodyHas
     * @param list<string>         $headers
     * @param list<string>         $bodyLacks
     */
    #[DataProvider('requests')]
    public function testEachPlatformSpeaksTheApiOfItsProvider(Provider $provider, ?string $address, string $model, string $request, array $bodyHas, array $headers, array $bodyLacks, string $answer): void
    {
        $sent = ['request' => '', 'headers' => [], 'body' => []];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $answer): MockResponse {
            $sent = ['request' => "{$method} {$url}", 'headers' => array_map(static fn (string $header): string => strtolower(explode(':', $header, 2)[0]).':'.explode(':', $header, 2)[1], $options['headers']), 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse($answer, ['response_headers' => ['content-type' => 'application/json']]);
        });
        // OpenAI would get its reasoning option from a model that thinks only: not asked here.
        $options = $provider->limit(50) + $provider->reasoningOff(false);

        $platform = $provider->platform($address, 'sk-test', $http);
        $text = $platform->invoke($model, new MessageBag(Message::ofUser('Say ok')), $options)->asText();

        self::assertSame('ok', $text);
        self::assertSame($request, $sent['request']);
        foreach ($bodyHas as $name => $value) {
            self::assertSame($value, $sent['body'][$name] ?? null, "The body says {$name}");
        }
        foreach ($bodyLacks as $name) {
            self::assertArrayNotHasKey($name, $sent['body'], "The body does not say {$name}");
        }
        foreach ($headers as $header) {
            self::assertContains($header, $sent['headers']);
        }
    }
}
