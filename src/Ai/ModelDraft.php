<?php

namespace App\Ai;

/**
 * What the player has chosen so far on the model screen, kept in the session between the steps. The API key
 * is in it only sealed (Encryption), and the draft is dropped once saved or abandoned.
 */
final readonly class ModelDraft
{
    public function __construct(
        public Provider $provider,
        public ?string $address,
        /** The API key, sealed with Encryption; null when there is none. */
        #[\SensitiveParameter]
        public ?string $sealedKey,
        public string $guesserModel = '',
        public string $drawerModel = '',
        public bool $reasoningOff = false,
        /** The fingerprint of the draft when both roles passed the check; "Save" only saves a draft that was checked. */
        public ?string $checked = null,
    ) {
    }

    /**
     * Changes whenever what the check judged changes.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [$this->provider->value, $this->address, (string) $this->sealedKey, $this->guesserModel, $this->drawerModel, $this->reasoningOff ? '1' : '0']));
    }

    public function withModels(string $guesserModel, string $drawerModel, bool $reasoningOff): self
    {
        return new self($this->provider, $this->address, $this->sealedKey, $guesserModel, $drawerModel, $reasoningOff);
    }

    public function passedTheCheck(): self
    {
        return new self($this->provider, $this->address, $this->sealedKey, $this->guesserModel, $this->drawerModel, $this->reasoningOff, $this->fingerprint());
    }

    public function isChecked(): bool
    {
        return $this->checked === $this->fingerprint();
    }

    public function hasModels(): bool
    {
        return '' !== $this->guesserModel && '' !== $this->drawerModel;
    }

    /**
     * What each role calls, for the check: the same provider and key, the model of the role.
     *
     * @return array{RoleSetup, RoleSetup} The drawer, then the guesser
     */
    public function setups(#[\SensitiveParameter] ?string $apiKey): array
    {
        $reasoningOff = $this->reasoningOff && $this->provider->hasReasoningSwitch();

        return [
            new RoleSetup($this->provider, $this->address, $apiKey, $this->drawerModel, $reasoningOff),
            new RoleSetup($this->provider, $this->address, $apiKey, $this->guesserModel, $reasoningOff),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'address' => $this->address,
            'key' => $this->sealedKey,
            'guesser' => $this->guesserModel,
            'drawer' => $this->drawerModel,
            'reasoning_off' => $this->reasoningOff,
            'checked' => $this->checked,
        ];
    }

    /**
     * @param array<string, mixed> $data
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
            \is_string($data['guesser'] ?? null) ? $data['guesser'] : '',
            \is_string($data['drawer'] ?? null) ? $data['drawer'] : '',
            true === ($data['reasoning_off'] ?? false),
            \is_string($data['checked'] ?? null) ? $data['checked'] : null,
        );
    }

    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'address' => $this->address, 'sealedKey' => null === $this->sealedKey ? null : '…', 'guesserModel' => $this->guesserModel, 'drawerModel' => $this->drawerModel];
    }
}
