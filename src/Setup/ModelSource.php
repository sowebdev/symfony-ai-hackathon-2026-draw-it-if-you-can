<?php

namespace App\Setup;

/**
 * Where the model runs, the one question of `make install` (app:setup). Both answers speak the
 * API of OpenAI; what tells them apart is the key (needed in the cloud, optional on a server of
 * your own) and above all reasoning, which a local model must not do.
 */
enum ModelSource: string
{
    case Cloud = 'cloud';
    case Local = 'local';

    public const string LOCAL_MODEL = 'hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL';

    public function label(): string
    {
        return match ($this) {
            self::Cloud => "A cloud API compatible with OpenAI's (OpenAI, Anthropic, Mistral...)",
            self::Local => 'A server you run, on this machine or your network (Ollama, LM Studio, llama.cpp...)',
        };
    }

    public function defaultUrl(): ?string
    {
        return self::Local === $this ? 'http://host.docker.internal:11434' : null;
    }

    public function defaultModel(): ?string
    {
        return self::Local === $this ? self::LOCAL_MODEL : null;
    }

    public function needsApiKey(): bool
    {
        return self::Cloud === $this;
    }

    /**
     * A local model that reasons takes tens of seconds per word; a cloud model answers fast anyway,
     * and some APIs refuse the option that turns reasoning off.
     */
    public function reasoningOffByDefault(): bool
    {
        return self::Local === $this;
    }

    /**
     * The same model for both roles; .env.local can still give each role its own.
     *
     * @return array<string, string>
     */
    public static function values(string $url, string $model, string $apiKey, bool $reasoningOff): array
    {
        $values = [];
        foreach (['DRAWER', 'GUESSER'] as $role) {
            $values["AI_{$role}_BASE_URL"] = $url;
            $values["AI_{$role}_MODEL"] = $model;
            $values["AI_{$role}_API_KEY"] = $apiKey;
            $values["AI_{$role}_DISABLE_THINKING"] = $reasoningOff ? 'true' : 'false';
        }

        return $values;
    }
}
