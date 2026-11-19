<?php

namespace App\Ai;

/**
 * What a role needs to call its model: who answers, where, with which key, which model. Read from the
 * setting of the game, or from the AI_* variables when there is none. It holds the API key: it goes
 * to the platform and nowhere else, and is hidden from var_dump() and from the debug output.
 */
final readonly class RoleSetup
{
    public function __construct(
        public Provider $provider,
        public ?string $address,
        #[\SensitiveParameter]
        private ?string $apiKey,
        public string $model,
        public bool $reasoningOff,
    ) {
    }

    /**
     * @internal for the factory of platforms
     */
    public function apiKey(): ?string
    {
        return '' === $this->apiKey ? null : $this->apiKey;
    }

    /**
     * "qwen3.8-27b-vision at http://gate:8001" or "claude-haiku-5-5 at Anthropic": for the messages
     * that tell what to fix. Never the key.
     */
    public function describe(): string
    {
        return \sprintf('%s at %s', $this->model, $this->place());
    }

    /**
     * Where the model answers: its address, or the name of the provider when the address is not a choice.
     */
    public function place(): string
    {
        return $this->provider->hasAddress() ? $this->provider->addressOr($this->address) : $this->provider->label();
    }

    /**
     * What makes another platform: a change of provider, address or key is a new check of the models.
     */
    public function fingerprint(): string
    {
        return hash('xxh3', implode("\0", [$this->provider->value, $this->address, $this->model, (string) $this->apiKey]));
    }

    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'address' => $this->address, 'apiKey' => null === $this->apiKey ? null : '…', 'model' => $this->model, 'reasoningOff' => $this->reasoningOff];
    }
}
