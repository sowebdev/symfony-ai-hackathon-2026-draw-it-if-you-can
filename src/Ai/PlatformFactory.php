<?php

namespace App\Ai;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Builds the platform of a provider, an address and a key. A test replaces it with a fake model.
 */
#[AsAlias(RuntimePlatformFactory::class)]
interface PlatformFactory
{
    /**
     * @param int|null $maxDurationS The longest a whole call may last, answer included; null: the HTTP client's own limits
     */
    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, ?int $maxDurationS = null): PlatformInterface;
}
