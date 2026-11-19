<?php

namespace App\Ai;

/**
 * What the player has chosen so far for one role on the models screen. The API key is in it only
 * sealed (Encryption), and only when the player typed one: null means the key already stored for the place.
 */
final readonly class RoleDraft
{
    public function __construct(
        public Provider $provider,
        public ?string $address,
        #[\SensitiveParameter]
        public ?string $sealedKey = null,
        /** Empty while the player has not chosen it yet. */
        public string $model = '',
        public bool $reasoningOff = false,
    ) {
    }

    public function withModel(string $model, bool $reasoningOff): self
    {
        return new self($this->provider, $this->address, $this->sealedKey, $model, $reasoningOff && $this->provider->hasReasoningSwitch());
    }

    public function isAt(Provider $provider, ?string $address): bool
    {
        return $this->provider === $provider && $provider->addressOr($this->address) === $provider->addressOr($address);
    }

    public function view(Role $role): RoleView
    {
        return new RoleView($role, $this->provider, $this->address, $this->model, $this->reasoningOff);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['provider' => $this->provider->value, 'address' => $this->address, 'key' => $this->sealedKey, 'model' => $this->model, 'reasoning_off' => $this->reasoningOff];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $provider = \is_string($data['provider'] ?? null) ? Provider::tryFrom($data['provider']) : null;
        if (null === $provider) {
            return null;
        }

        return new self(
            $provider,
            \is_string($data['address'] ?? null) ? $data['address'] : null,
            \is_string($data['key'] ?? null) ? $data['key'] : null,
            \is_string($data['model'] ?? null) ? $data['model'] : '',
            true === ($data['reasoning_off'] ?? false),
        );
    }

    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'address' => $this->address, 'sealedKey' => null === $this->sealedKey ? null : '…', 'model' => $this->model];
    }
}
