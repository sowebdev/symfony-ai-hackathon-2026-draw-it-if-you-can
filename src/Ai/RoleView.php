<?php

namespace App\Ai;

/**
 * The model a role has of its own, as the screen may show it: there is no key in it.
 */
final readonly class RoleView
{
    public function __construct(
        public Role $role,
        public Provider $provider,
        public ?string $address,
        public string $model,
        public bool $reasoningOff,
    ) {
    }

    /**
     * Whether another choice is the same place: the same provider and, where the address is a choice, the same address.
     */
    public function isAt(Provider $provider, ?string $address): bool
    {
        return $this->provider === $provider && $provider->addressOr($this->address) === $provider->addressOr($address);
    }
}
