<?php

namespace App\Ai;

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * In development only: every call to a model goes through one TraceablePlatform of Symfony AI, so that the AI
 * panel of the profiler shows it. That panel reads the traceable platforms of the container when it is built,
 * and the game builds its platforms later, at runtime (docs/adr/0004-platforms-built-at-runtime-from-a-stored-setting.md).
 * So one traceable platform is a service (app.traced_platform, config/services.yaml), and it relays each call to
 * the runtime platform it was made on: this class, which is also the platform it wraps.
 */
#[When(env: 'dev')]
#[AsDecorator(RuntimePlatformFactory::class)]
final class TracedPlatforms implements PlatformFactory, PlatformInterface
{
    /** The runtime platform of the call being relayed: one request at a time per PHP thread. */
    private ?PlatformInterface $current = null;

    /**
     * @param \Closure(): TraceablePlatform $traced
     */
    public function __construct(
        private readonly PlatformFactory $inner,
        #[AutowireServiceClosure('app.traced_platform')]
        private readonly \Closure $traced,
    ) {
    }

    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, ?int $maxDurationS = null): PlatformInterface
    {
        $platform = $this->inner->create($provider, $address, $apiKey, $maxDurationS);
        $relay = function (PlatformInterface $platform): void {
            $this->current = $platform;
        };

        return new class($platform, ($this->traced)(), $relay) implements PlatformInterface {
            /**
             * @param \Closure(PlatformInterface): void $relay
             */
            public function __construct(private readonly PlatformInterface $platform, private readonly TraceablePlatform $traced, private readonly \Closure $relay)
            {
            }

            public function invoke(string|\Symfony\AI\Platform\Model $model, array|string|object $input, array $options = []): DeferredResult
            {
                ($this->relay)($this->platform);

                return $this->traced->invoke($model, $input, $options);
            }

            public function getModelCatalog(): ModelCatalogInterface
            {
                return $this->platform->getModelCatalog();
            }
        };
    }

    public function invoke(string|\Symfony\AI\Platform\Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        return ($this->current ?? throw new \LogicException('No platform to relay the call to.'))->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return ($this->current ?? throw new \LogicException('No platform to relay the call to.'))->getModelCatalog();
    }
}
