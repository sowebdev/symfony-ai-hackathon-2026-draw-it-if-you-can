<?php

namespace App\Ai;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the platform from the bridge's own Factory when it is first needed, and keeps it for the
 * rest of the process, by provider, address and hash of the key: a new setting is a new platform.
 */
final class RuntimePlatformFactory implements PlatformFactory
{
    /** @var array<string, PlatformInterface> */
    private array $platforms = [];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, ?int $maxDurationS = null): PlatformInterface
    {
        $key = implode('|', [$provider->value, $provider->addressOr($address), hash('sha256', (string) $apiKey), $maxDurationS ?? 'none']);

        return $this->platforms[$key] ??= $provider->platform($address, $apiKey, null === $maxDurationS ? $this->httpClient : $this->httpClient->withOptions(['max_duration' => $maxDurationS]));
    }
}
