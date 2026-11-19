<?php

namespace App\Ai;

/**
 * A stored account as the screen may show it: everything but the API key, of which it has the end only.
 */
final readonly class AccountView
{
    public function __construct(
        public int $id,
        public Provider $provider,
        public ?string $address,
        public bool $hasApiKey,
        /** "…3f9a" */
        public ?string $maskedApiKey,
        /** @var list<Role> The roles that call it: while there are some, it cannot be forgotten */
        public array $usedBy,
    ) {
    }

    public function isUsed(): bool
    {
        return [] !== $this->usedBy;
    }
}
