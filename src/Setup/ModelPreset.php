<?php

namespace App\Setup;

/**
 * The ways to give the game its models, offered by `make install` (app:setup).
 * The models of the API presets are the ones tried in the README (« Which model? »).
 */
enum ModelPreset: string
{
    case Anthropic = 'anthropic';
    case Mistral = 'mistral';
    case Server = 'server';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'An Anthropic API key (recommended: the best drawings we tried)',
            self::Mistral => 'A Mistral API key',
            self::Server => 'A model server you already run (Ollama, LM Studio, llama.cpp...), on this machine or elsewhere',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Anthropic => 'https://api.anthropic.com',
            self::Mistral => 'https://api.mistral.ai',
            self::Server => 'http://host.docker.internal:11434',
        };
    }

    public function drawerModel(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-sonnet-5-5',
            self::Mistral => 'mistral-medium-3.5',
            self::Server => 'hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL',
        };
    }

    public function guesserModel(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-haiku-5-5',
            self::Mistral => 'mistral-small-latest',
            self::Server => 'hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL',
        };
    }

    public function needsApiKey(): bool
    {
        return self::Server !== $this;
    }

    /**
     * A local model that reasons takes tens of seconds per answer; a cloud API may refuse the option.
     */
    public function disableThinking(): bool
    {
        return self::Server === $this;
    }

    /**
     * @return array<string, string>
     */
    public function values(string $baseUrl, string $drawerModel, string $guesserModel, string $apiKey): array
    {
        $values = [];
        foreach (['DRAWER' => $drawerModel, 'GUESSER' => $guesserModel] as $role => $model) {
            $values["AI_{$role}_BASE_URL"] = $baseUrl;
            $values["AI_{$role}_MODEL"] = $model;
            $values["AI_{$role}_API_KEY"] = $apiKey;
            $values["AI_{$role}_DISABLE_THINKING"] = $this->disableThinking() ? 'true' : 'false';
        }

        return $values;
    }
}
