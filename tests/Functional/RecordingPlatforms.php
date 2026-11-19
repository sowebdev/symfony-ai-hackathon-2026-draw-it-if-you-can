<?php

namespace App\Tests\Functional;

use App\Ai\PlatformFactory;
use App\Ai\Provider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * Builds no platform and calls no provider: it notes which platform was asked for, and what each
 * call said to it (the model and the options), and the fake model answers "ok".
 */
final class RecordingPlatforms implements PlatformFactory
{
    /** @var list<array{Provider, ?string, ?string}> */
    public array $created = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $invoked = [];

    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey): PlatformInterface
    {
        $this->created[] = [$provider, $address, $apiKey];

        return MockPlatformFactory::createPlatform(function ($model, $input, array $options): string {
            $this->invoked[] = [$model->getName(), $options];

            return 'ok';
        });
    }
}
