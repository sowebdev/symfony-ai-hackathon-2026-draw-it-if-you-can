<?php

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog as AnthropicCatalog;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiCatalog;
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
    case Ollama = 'ollama';
    case OwnServer = 'own_server';

    /** Where the models of Anthropic are listed, whatever the setting says: its address is not a choice. */
    public const string ANTHROPIC_URL = 'https://api.anthropic.com';

    /** Where the models of OpenAI are listed: its address is not a choice either. */
    public const string OPENAI_URL = 'https://api.openai.com';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::Mistral => 'Mistral',
            self::Ollama => 'Ollama',
            self::OwnServer => 'Your own server (OpenAI-compatible)',
        };
    }

    /**
     * The address to propose, null when the provider has none to choose or none to guess.
     */
    public function defaultAddress(): ?string
    {
        return match ($this) {
            self::Mistral => 'https://api.mistral.ai',
            self::Ollama => 'http://host.docker.internal:11434',
            self::Anthropic, self::OpenAi, self::OwnServer => null,
        };
    }

    /**
     * Whether the address is a setting: Anthropic and OpenAI are always at the same place.
     */
    public function hasAddress(): bool
    {
        return null === $this->dedicatedAddress();
    }

    public function needsKey(): bool
    {
        return match ($this) {
            self::Anthropic, self::OpenAi, self::Mistral => true,
            self::Ollama, self::OwnServer => false,
        };
    }

    /**
     * Whether the platform is the Generic bridge: any model name goes, none is known to the bridge.
     */
    public function isGeneric(): bool
    {
        return match ($this) {
            self::Anthropic, self::OpenAi => false,
            self::Mistral, self::Ollama, self::OwnServer => true,
        };
    }

    /**
     * Ollama stays on the Generic bridge: its own bridge sends no argument delta for a tool call,
     * so the strokes would all come at the end (ADR 0004).
     */
    public function platform(?string $address, ?string $apiKey, HttpClientInterface $httpClient): PlatformInterface
    {
        $apiKey = '' === $apiKey ? null : $apiKey;

        return match ($this) {
            self::Anthropic => AnthropicFactory::createPlatform($apiKey ?? '', $httpClient, $this->catalog()),
            self::OpenAi => OpenAiFactory::createPlatform($apiKey ?? '', $httpClient, $this->catalog()),
            self::Mistral, self::Ollama, self::OwnServer => GenericFactory::createPlatform(
                rtrim($address ?? $this->defaultAddress() ?? '', '/'),
                $apiKey,
                $httpClient,
            ),
        };
    }

    /**
     * The models the bridge knows, with what each can do; null for a Generic provider, which knows none.
     */
    public function bridgeCatalog(): ?ModelCatalogInterface
    {
        return match ($this) {
            self::Anthropic => new AnthropicCatalog(),
            self::OpenAi => new OpenAiCatalog(),
            self::Mistral, self::Ollama, self::OwnServer => null,
        };
    }

    /**
     * The catalog of the bridge, then one that accepts any other name: a model the catalog does not
     * know (a new one, a snapshot) must still be usable.
     */
    private function catalog(): ModelCatalogInterface
    {
        return new CompositeModelCatalog([
            $this->bridgeCatalog() ?? throw new \LogicException('A Generic provider has no catalog.'),
            new UnlistedModels(self::Anthropic === $this ? Claude::class : Gpt::class),
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
     * The longest answer of the model. The bridges convert the canonical option into the name of
     * their API (max_tokens for Generic and Anthropic); the Responses API of OpenAI names it so.
     *
     * @return array<string, mixed>
     */
    public function limit(int $tokens): array
    {
        return [PlatformOptions::MAX_OUTPUT_TOKENS => $tokens];
    }

    /**
     * What turns reasoning off, where the API understands it. The Anthropic bridge merges every
     * option into the body of the request, where `reasoning_effort` is a 400, and thinking is off
     * until asked: nothing to send. OpenAI: nothing on a model that does not think; on one that does,
     * the least effort every model of the family takes ("low"; "none" and "minimal" only the newer ones).
     *
     * @param bool $thinks Whether the catalog says the model reasons
     *
     * @return array<string, mixed>
     */
    public function reasoningOff(bool $thinks): array
    {
        return match ($this) {
            // llama.cpp reads chat_template_kwargs, Ollama reads reasoning_effort and ignores the
            // other (13/11/2026); a cloud API may refuse either, hence the switch of the setting.
            self::Mistral, self::Ollama, self::OwnServer => [
                'chat_template_kwargs' => ['enable_thinking' => false],
                'reasoning_effort' => 'none',
            ],
            self::OpenAi => $thinks ? ['reasoning' => ['effort' => 'low']] : [],
            self::Anthropic => [],
        };
    }

    /**
     * How long the list of its models is kept: a cloud list hardly changes, a server of one's own
     * does as soon as a model is pulled.
     */
    public function listTtl(): int
    {
        return match ($this) {
            self::Anthropic, self::OpenAi, self::Mistral => 3600,
            self::Ollama, self::OwnServer => 30,
        };
    }

    /**
     * The address the API answers at: the one of the setting, or the provider's own.
     */
    public function addressOr(?string $address): string
    {
        return rtrim($this->dedicatedAddress() ?? (null !== $address && '' !== $address ? $address : ($this->defaultAddress() ?? '')), '/');
    }

    private function dedicatedAddress(): ?string
    {
        return match ($this) {
            self::Anthropic => self::ANTHROPIC_URL,
            self::OpenAi => self::OPENAI_URL,
            default => null,
        };
    }
}
