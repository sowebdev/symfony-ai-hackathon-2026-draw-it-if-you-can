<?php

namespace App\Ai;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * The platform of a role, which writes each call to the ModelCallLog once it is over: when its result is read,
 * or at the end of its stream for an answer streamed (the drawer's strokes). The agent of the drawer calls it
 * several times a turn: one line each.
 */
final readonly class MeteredPlatform implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $platform,
        private Role $role,
        private RoleSetup $setup,
        private ModelCallLog $log,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $started = microtime(true);
        $record = fn (?TokenUsageInterface $usage, ?\Throwable $failure = null) => $this->log->record($this->role, $this->setup, microtime(true) - $started, $usage, $failure);
        try {
            $result = $this->platform->invoke($model, $input, $options);
        } catch (\Throwable $exception) {
            $record(null, $exception);

            throw $exception;
        }

        $result->onError(static fn (\Throwable $exception) => $record(null, $exception));
        $result->onConvert(static function (ResultInterface $converted) use ($record): ResultInterface {
            if ($converted instanceof StreamResult) {
                $converted->addListener(new class($record) extends AbstractStreamListener {
                    public function __construct(private readonly \Closure $record)
                    {
                    }

                    public function onComplete(CompleteEvent $event): void
                    {
                        $usage = $event->getResult()->getMetadata()->get('token_usage') ?? $event->getMetadata()->get('token_usage');
                        ($this->record)($usage instanceof TokenUsageInterface ? $usage : null);
                    }
                });

                return $converted;
            }
            $usage = $converted->getMetadata()->get('token_usage');
            $record($usage instanceof TokenUsageInterface ? $usage : null);

            return $converted;
        });

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
