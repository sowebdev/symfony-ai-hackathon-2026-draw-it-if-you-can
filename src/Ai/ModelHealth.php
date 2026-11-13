<?php

namespace App\Ai;

use Symfony\AI\Platform\Exception\BadRequestException;
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
                    $problem = self::explain($exception);
                }
                $item->expiresAfter(null === $problem ? self::HEALTHY_TTL_S : self::SICK_TTL_S);

                return $problem ?? '';
            });
            $report[] = ['role' => $role->value, 'model' => $model, 'ok' => '' === $problem, 'problem' => '' === $problem ? null : $problem];
        }

        return $report;
    }

    /**
     * What a failed call means for the player, when the cause is a common one.
     */
    public static function explain(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'Failed to connect') || str_contains($message, 'Could not resolve host') || str_contains($message, 'Connection refused')) {
            return 'Nothing answers at this address: start the model server (with Ollama: ollama serve), or set another address.';
        }
        if (preg_match('/\b40[13]\b/', $message) || stripos($message, 'unauthorized') !== false) {
            return 'The server refused the API key: check AI_<ROLE>_API_KEY. '.$message;
        }
        // The generic bridge of Symfony AI reads a text answer only; a reasoning model of Mistral
        // answers with blocks, its reasoning then its text.
        if ($exception instanceof \TypeError && str_contains($message, 'array given')) {
            return 'This model answers with reasoning blocks, which Symfony AI cannot read yet: choose a model that does not reason (e.g. mistral-medium-3.5 rather than mistral-large-4).';
        }
        // The generic bridge looks for the reason where OpenAI puts it; Mistral puts it elsewhere.
        if ($exception instanceof BadRequestException) {
            return 'Bad Request' === $message
                ? 'The server refused the request without a reason the game can read. Models that always reason refuse options of the game: choose one that does not reason.'
                : 'The server refused the request: '.$message;
        }

        return 'The call failed: '.$message;
    }
}
