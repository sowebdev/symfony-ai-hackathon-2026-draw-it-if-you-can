<?php

namespace App\Ai;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Whether each role can play, for the home page: the checks of make check, kept a few minutes so
 * that the home page does not call the models at each visit. A change of model or endpoint is a
 * new check.
 */
final readonly class ModelHealth
{
    /** A role that plays is checked again after this. */
    private const int HEALTHY_TTL_S = 300;

    /** A role that does not play is checked again soon: the model may be loading. */
    private const int SICK_TTL_S = 10;

    public function __construct(
        private Models $models,
        private ModelCheck $check,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{role: string, model: string, ok: bool, problem: ?string}>
     */
    public function report(bool $fresh = false): array
    {
        $report = [];
        foreach (Role::cases() as $role) {
            $model = $this->models->describe($role);
            $key = 'model_health.'.$role->value.'.'.hash('xxh3', $model);
            if ($fresh) {
                $this->cache->delete($key);
            }
            $problem = $this->cache->get($key, function (ItemInterface $item) use ($role): string {
                try {
                    $problem = $this->check->check($role);
                } catch (\Throwable $exception) {
                    $problem = 'The call failed: '.$exception->getMessage();
                }
                $item->expiresAfter(null === $problem ? self::HEALTHY_TTL_S : self::SICK_TTL_S);

                return $problem ?? '';
            });
            $report[] = ['role' => $role->value, 'model' => $model, 'ok' => '' === $problem, 'problem' => '' === $problem ? null : $problem];
        }

        return $report;
    }
}
