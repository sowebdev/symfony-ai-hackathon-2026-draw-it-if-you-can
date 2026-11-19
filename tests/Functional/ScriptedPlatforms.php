<?php

namespace App\Tests\Functional;

use App\Ai\PlatformFactory;
use App\Ai\Provider;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Builds no platform: whatever the provider, the fake model answers. No test calls a provider.
 */
final readonly class ScriptedPlatforms implements PlatformFactory
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, ?int $maxDurationS = null): PlatformInterface
    {
        return $this->platform;
    }
}
