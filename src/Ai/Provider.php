<?php

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\CompositeModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Who answers: how its platform is built, where it lives, what it needs, and what its API takes.
 * See docs/adr/0004-platforms-built-at-runtime-from-a-stored-setting.md.
 */
enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Mistral = 'mistral';

    /**
     * Any address that speaks the API of OpenAI, on the Generic bridge: Ollama, LM Studio or
     * llama.cpp on one's machine, OpenRouter, Groq or the Hugging Face router on the internet.
     */
    case Compatible = 'openai_compatible';

    /** Where the models of Anthropic are listed, whatever the setting says: its address is not a choice. */
    public const string ANTHROPIC_URL = 'https://api.anthropic.com';

    /** Where the models of OpenAI are listed: its address is not a choice either. */
    public const string OPENAI_URL = 'https://api.openai.com';

    /** Nor is the one of Mistral. */
    public const string MISTRAL_URL = 'https://api.mistral.ai';

    /**
     * The name in English, for logs and the console; the screen translates (provider.<value>).
     */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::Mistral => 'Mistral',
            self::Compatible => 'Another OpenAI-compatible service',
        };
    }

    /**
     * Whether the address is a setting: the three cloud providers are always at the same place.
     */
    public function hasAddress(): bool
    {
        return null === $this->dedicatedAddress();
    }

    public function needsKey(): bool
    {
        return self::Compatible !== $this;
    }

    /**
     * Whether the platform is the Generic bridge: any model name goes, none is known to the bridge.
     */
    public function isGeneric(): bool
    {
        return self::Compatible === $this;
    }

    /**
     * Ollama is not a provider of its own: the game does not use its bridge, which sends no argument
     * delta for a tool call, so the strokes would all come at the end (ADR 0004). Ollama is an
     * address on the Generic bridge, like LM Studio or OpenRouter.
     */
    public function platform(?string $address, #[\SensitiveParameter] ?string $apiKey, HttpClientInterface $httpClient): PlatformInterface
    {
        $apiKey = '' === $apiKey ? null : $apiKey;

        return match ($this) {
            self::Anthropic => AnthropicFactory::createPlatform($apiKey ?? '', $httpClient, $this->catalog()),
            self::OpenAi => OpenAiFactory::createPlatform($apiKey ?? '', $httpClient, $this->catalog()),
            self::Mistral => MistralFactory::createPlatform($apiKey ?? '', $httpClient, $this->catalog(), baseUrl: self::MISTRAL_URL),
            self::Compatible => GenericFactory::createPlatform(rtrim($address ?? '', '/'), $apiKey, $httpClient),
        };
    }

    /**
     * The models the bridge knows, with what each can do; null for the Generic bridge, which knows none.
     */
    public function bridgeCatalog(): ?ModelCatalogInterface
    {
        return match ($this) {
            self::Anthropic => new AnthropicCatalog(),
            self::OpenAi => new OpenAiCatalog(),
            self::Mistral => new MistralCatalog(),
            self::Compatible => null,
        };
    }

    /**
     * The catalog of the bridge, then one that accepts any other name: a model the catalog does not
     * know (a new one, a snapshot) must still be usable.
     */
    private function catalog(): ModelCatalogInterface
    {
        return new CompositeModelCatalog([
            $this->bridgeCatalog() ?? throw new \LogicException('The Generic bridge has no catalog.'),
            new UnlistedModels(match ($this) {
                self::Anthropic => Claude::class,
                self::Mistral => Mistral::class,
                default => Gpt::class,
            }),
        ]);
    }

    /**
     * What the model can do, as far as its bridge knows; null if it does not know it.
     */
    public function knows(string $model): ?Model
    {
        $catalog = $this->bridgeCatalog();

        return '' !== $model && isset($catalog?->getModels()[$model]) ? $catalog->getModel($model) : null;
    }

    /**
     * Whether the catalog says the model reasons; false for a model it does not know.
     */
    public function thinks(string $model): bool
    {
        return $this->knows($model)?->supports(Capability::THINKING) ?? false;
    }

    /**
     * The longest answer of the model. The bridges convert the canonical option into the name of
     * their API (max_tokens for Generic, Anthropic and Mistral; max_output_tokens for the Responses
     * API of OpenAI).
     *
     * @return array<string, mixed>
     */
    public function limit(int $tokens): array
    {
        return [PlatformOptions::MAX_OUTPUT_TOKENS => $tokens];
    }

    /**
     * What keeps the model from reasoning for long, since the game needs short answers every few
     * seconds and a wrong option is a 400. Where the catalog tells whether a model thinks, the
     * game decides and the player sees no switch:
     *
     * - Anthropic: thinking is off until asked, so nothing is sent, thinking model or not (the
     *   bridge merges every option into the body, where `reasoning_effort` would be a 400);
     * - OpenAI: nothing for a model that does not think, `reasoning.effort: low` (the least effort
     *   every model of the family takes) for one the catalog says thinks;
     * - Mistral: nothing. Its bridge reads the blocks of a model that reasons, but which value of
     *   `reasoning_effort` each model takes is not in its catalog: nothing is sent when unsure;
     * - a model typed by name, not in the catalog: nothing.
     *
     * Another OpenAI-compatible service: the game cannot know, the player's switch decides. llama.cpp
     * reads `chat_template_kwargs`, Ollama reads `reasoning_effort` and ignores the other (13/11/2026);
     * a cloud API may refuse either, hence a switch that is on by default for a local address only.
     *
     * @param bool $switchOff The "turn reasoning off" switch of the setting, read for Compatible only
     *
     * @return array<string, mixed>
     */
    public function reasoningOptions(string $model, bool $switchOff): array
    {
        return match ($this) {
            self::Compatible => $switchOff ? ['chat_template_kwargs' => ['enable_thinking' => false], 'reasoning_effort' => 'none'] : [],
            self::OpenAi => $this->thinks($model) ? ['reasoning' => ['effort' => 'low']] : [],
            self::Anthropic, self::Mistral => [],
        };
    }

    /**
     * Whether the player sees the reasoning switch: only where the game cannot know.
     */
    public function hasReasoningSwitch(): bool
    {
        return self::Compatible === $this;
    }

    /**
     * Whether the switch starts on for this address: a local model that thinks takes tens of seconds
     * per word, a cloud API may refuse the option.
     */
    public function reasoningOffByDefault(?string $address): bool
    {
        return self::Compatible === $this && LocalAddress::isLocal($address);
    }

    /**
     * How long the list of its models is kept: a cloud list hardly changes, a server of one's own
     * does as soon as a model is pulled.
     */
    public function listTtl(): int
    {
        return $this->hasAddress() ? 30 : 3600;
    }

    /**
     * The address the API answers at: the one of the setting, or the provider's own.
     */
    public function addressOr(?string $address): string
    {
        return rtrim($this->dedicatedAddress() ?? (null !== $address ? $address : ''), '/');
    }

    private function dedicatedAddress(): ?string
    {
        return match ($this) {
            self::Anthropic => self::ANTHROPIC_URL,
            self::OpenAi => self::OPENAI_URL,
            self::Mistral => self::MISTRAL_URL,
            self::Compatible => null,
        };
    }
}
