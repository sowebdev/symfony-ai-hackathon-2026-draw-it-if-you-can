<?php

namespace App\Tests\Unit\Ai;

use App\Ai\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderTest extends TestCase
{
    public function testWhatEachProviderIsAndNeeds(): void
    {
        // Four providers: Ollama is an address of the last one, not a provider of its own.
        // Four providers: Ollama is an address of the last one.
        foreach (['ollama', 'own_server'] as $old) {
            self::assertNotContains($old, array_map(static fn (Provider $p): string => $p->value, Provider::cases()));
        }

        self::assertSame([true, true, true, false], array_map(static fn (Provider $p): bool => $p->needsKey(), Provider::cases()));
        // The three clouds are always at the same place; the last one has an address to set.
        self::assertSame([false, false, false, true], array_map(static fn (Provider $p): bool => $p->hasAddress(), Provider::cases()));
        self::assertSame([false, false, false, true], array_map(static fn (Provider $p): bool => $p->isGeneric(), Provider::cases()));
        self::assertSame('https://api.mistral.ai', Provider::Mistral->addressOr('http://ignored'));
        self::assertSame('http://gate:8001', Provider::Compatible->addressOr('http://gate:8001/'));
        // Only where the game cannot know does the player see the reasoning switch.
        self::assertSame([false, false, false, true], array_map(static fn (Provider $p): bool => $p->hasReasoningSwitch(), Provider::cases()));
        foreach (Provider::cases() as $provider) {
            self::assertNotSame('', $provider->label());
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'localhost' => ['http://localhost:11434', true];
        yield 'loopback' => ['http://127.0.0.1:8080', true];
        yield 'docker host' => ['http://host.docker.internal:11434', true];
        yield 'private 192.168' => ['http://192.168.1.10:1234', true];
        yield 'private 10' => ['http://10.0.0.5', true];
        yield 'private 172.16' => ['http://172.16.3.4:8000', true];
        yield 'a name of the network' => ['http://gate:8001', true];
        yield 'a .local name' => ['http://studio.local:1234', true];
        yield 'OpenRouter' => ['https://openrouter.ai/api', false];
        yield 'Groq' => ['https://api.groq.com/openai', false];
        yield 'a public address' => ['http://8.8.8.8', false];
        yield 'no address' => ['', false];
    }

    #[DataProvider('addresses')]
    public function testTheReasoningSwitchStartsOnForALocalAddressOnly(string $address, bool $local): void
    {
        self::assertSame($local, Provider::Compatible->reasoningOffByDefault($address));
        self::assertFalse(Provider::Anthropic->reasoningOffByDefault($address), 'No switch, nothing to start');
    }

    public function testTheLimitIsTheCanonicalOptionOfEveryBridge(): void
    {
        foreach (Provider::cases() as $provider) {
            self::assertSame([PlatformOptions::MAX_OUTPUT_TOKENS => 1000], $provider->limit(1000));
        }
    }

    /**
     * The first model of a catalog that thinks, and the first that does not.
     *
     * @return array{string, string}
     */
    private static function thinkingAndNot(Provider $provider): array
    {
        $thinks = $plain = null;
        foreach ($provider->bridgeCatalog()?->getModels() ?? [] as $name => $model) {
            if (\in_array(Capability::THINKING, $model['capabilities'], true)) {
                $thinks ??= $name;
            } else {
                $plain ??= $name;
            }
        }
        self::assertNotNull($thinks);
        self::assertNotNull($plain);

        return [$thinks, $plain];
    }

    /**
     * The options that keep a model from reasoning, for each provider, a model that thinks and one that
     * does not, a model typed by name, and the switch on and off (it counts for the Generic case only).
     */
    public function testWhatKeepsAModelFromReasoning(): void
    {
        $generic = ['chat_template_kwargs' => ['enable_thinking' => false], 'reasoning_effort' => 'none'];

        // Another OpenAI-compatible service: the game cannot know, the switch decides.
        self::assertSame($generic, Provider::Compatible->reasoningOptions('qwen3:8b', true));
        self::assertSame([], Provider::Compatible->reasoningOptions('qwen3:8b', false), 'A cloud API may refuse the option: nothing when the switch is off');

        // Anthropic: thinking is off until asked, and its API would answer 400 to reasoning_effort.
        [$thinks, $plain] = self::thinkingAndNot(Provider::Anthropic);
        foreach ([true, false] as $switch) {
            self::assertSame([], Provider::Anthropic->reasoningOptions($thinks, $switch));
            self::assertSame([], Provider::Anthropic->reasoningOptions($plain, $switch));
            self::assertSame([], Provider::Anthropic->reasoningOptions('claude-not-yet-released', $switch));
        }

        // OpenAI: the least effort for a model the catalog says thinks, nothing for the others, whatever the switch.
        [$thinks, $plain] = self::thinkingAndNot(Provider::OpenAi);
        foreach ([true, false] as $switch) {
            self::assertSame(['reasoning' => ['effort' => 'low']], Provider::OpenAi->reasoningOptions($thinks, $switch));
            self::assertSame([], Provider::OpenAi->reasoningOptions($plain, $switch));
            self::assertSame([], Provider::OpenAi->reasoningOptions('gpt-9-not-yet-released', $switch));
        }

        // Mistral: nothing is sent when unsure, a model that thinks or not.
        [$thinks, $plain] = self::thinkingAndNot(Provider::Mistral);
        foreach ([true, false] as $switch) {
            self::assertSame([], Provider::Mistral->reasoningOptions($thinks, $switch));
            self::assertSame([], Provider::Mistral->reasoningOptions($plain, $switch));
            self::assertSame([], Provider::Mistral->reasoningOptions('mistral-not-yet-released', $switch));
        }
    }

    public function testTheCatalogKnowsWhatABridgeKnows(): void
    {
        self::assertNull(Provider::Compatible->bridgeCatalog(), 'The Generic bridge knows no model');
        self::assertNull(Provider::Compatible->knows('qwen3'));
        self::assertTrue(Provider::Mistral->knows('mistral-small-latest')?->supports(Capability::INPUT_IMAGE));
        self::assertTrue(Provider::Anthropic->knows('claude-haiku-5-5')?->supports(Capability::TOOL_CALLING));
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
        yield 'Mistral, on its own bridge: the limit as max_tokens, no reasoning option' => [
            Provider::Mistral, null, 'mistral-small-latest', 'POST https://api.mistral.ai/v1/chat/completions',
            ['max_tokens' => 50], ['authorization: Bearer sk-test'], ['max_output_tokens', 'reasoning_effort', 'chat_template_kwargs'], $generic,
        ];
        yield 'Mistral, a model the catalog does not know' => [
            Provider::Mistral, null, 'mistral-not-yet-released', 'POST https://api.mistral.ai/v1/chat/completions',
            ['max_tokens' => 50, 'model' => 'mistral-not-yet-released'], ['authorization: Bearer sk-test'], ['reasoning_effort'], $generic,
        ];
        yield 'Mistral, a model that answers in blocks, its reasoning then its text' => [
            Provider::Mistral, null, 'mistral-medium-2604', 'POST https://api.mistral.ai/v1/chat/completions',
            ['max_tokens' => 50], ['authorization: Bearer sk-test'], ['reasoning_effort'],
            '{"choices":[{"message":{"role":"assistant","content":[{"type":"thinking","thinking":[{"type":"text","text":"hm"}]},{"type":"text","text":"ok"}]},"finish_reason":"stop"}]}',
        ];
        yield 'Ollama, which is an address on the Generic bridge' => [
            Provider::Compatible, 'http://host.docker.internal:11434', 'qwen3:8b', 'POST http://host.docker.internal:11434/v1/chat/completions',
            ['max_tokens' => 50, 'reasoning_effort' => 'none', 'chat_template_kwargs' => ['enable_thinking' => false]], [], ['max_output_tokens'], $generic,
        ];
        yield 'OpenRouter, with a slash at the end of its address' => [
            Provider::Compatible, 'https://openrouter.ai/api/', 'qwen', 'POST https://openrouter.ai/api/v1/chat/completions',
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
        $options = $provider->limit(50) + $provider->reasoningOptions($model, true);

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
