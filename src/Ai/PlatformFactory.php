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
    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey): PlatformInterface;
}
