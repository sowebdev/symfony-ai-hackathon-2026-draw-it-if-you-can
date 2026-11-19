<?php

namespace App\Ai;

use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ModelSettings $settings,
        private ModelCheck $check,
        private CacheInterface $cache,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{role: string, model: string, ok: bool, problem: ?string}> Empty when no model is chosen yet
     */
    public function report(bool $fresh = false): array
    {
        $report = [];
        if (!$this->settings->isStored()) {
            return $report;
        }
        foreach (Role::cases() as $role) {
            $setup = $this->models->setup($role);
            $model = $this->translator->trans('label.model_at', ['%model%' => $setup->model, '%address%' => $setup->place()]);
            $key = 'model_health.'.$role->value.'.'.hash('xxh3', $this->models->fingerprint($role));
            if ($fresh) {
                $this->cache->delete($key);
            }
            // Kept as an id and its parameters, not as a sentence: the language is the one of whoever looks.
            $problem = $this->cache->get($key, function (ItemInterface $item) use ($role): ?array {
                try {
                    $problem = $this->check->check($role);
                } catch (\Throwable $exception) {
                    $setup = $this->models->setup($role);
                    $problem = self::explain($exception, $setup->provider, $setup->reasoningOff);
                }
                $item->expiresAfter(null === $problem ? self::HEALTHY_TTL_S : self::SICK_TTL_S);

                return $problem?->toArray();
            });
            $report[] = ['role' => $role->value, 'model' => $model, 'ok' => null === $problem, 'problem' => null === $problem ? null : Problem::fromArray($problem)->trans($this->translator)];
        }

        return $report;
    }

    /**
     * What a failed call means for the player, when the cause is a common one. Where the provider has
     * the reasoning switch, the advice says which way to flip it.
     */
    public static function explain(\Throwable $exception, ?Provider $provider = null, bool $switchOff = false): Problem
    {
        $message = $exception->getMessage();
        $hasSwitch = $provider?->hasReasoningSwitch() ?? false;
        if (str_contains($message, 'Failed to connect') || str_contains($message, 'Could not resolve host') || str_contains($message, 'Connection refused')) {
            return new Problem('problem.no_server');
        }
        if (preg_match('/\b40[13]\b/', $message) || false !== stripos($message, 'unauthorized')) {
            return new Problem('problem.refused_key', ['%detail%' => $message]);
        }
        if (preg_match('/model ["\']?([^"\'\s]+)["\']? not found/i', $message, $missing)) {
            return new Problem('problem.model_missing', ['%model%' => $missing[1]]);
        }
        // A very slow answer: no byte for as long as the client waits, or a model that loads or thinks.
        for ($previous = $exception; null !== $previous; $previous = $previous->getPrevious()) {
            if ($previous instanceof TimeoutExceptionInterface) {
                return new Problem('problem.slow', advice: $hasSwitch ? ($switchOff ? 'advice.reasoning_choose' : 'advice.reasoning_tick') : 'advice.reasoning_choose');
            }
        }
        // The Generic bridge reads a text answer only: a model that reasons may answer with blocks,
        // its reasoning then its text (the bridge of Mistral reads them).
        if ($exception instanceof \TypeError && str_contains($message, 'array given')) {
            return new Problem('problem.reasoning_blocks', advice: $hasSwitch && !$switchOff ? 'advice.reasoning_tick' : 'advice.reasoning_choose');
        }
        // The Generic bridge looks for the reason where OpenAI puts it; others put it elsewhere.
        if ($exception instanceof BadRequestException) {
            $refusedOption = 'Bad Request' === $message || (bool) preg_match('/reasoning|thinking|chat_template|unknown (?:parameter|field)|extra inputs|unrecognized request argument/i', $message);
            if ($hasSwitch && $switchOff && $refusedOption) {
                return new Problem('Bad Request' === $message ? 'problem.refused_without_reason' : 'problem.refused', ['%detail%' => $message], 'advice.reasoning_untick');
            }

            return 'Bad Request' === $message
                ? new Problem('problem.refused_without_reason', advice: 'advice.reasoning_choose')
                : new Problem('problem.refused', ['%detail%' => $message]);
        }

        return new Problem('problem.call_failed', ['%detail%' => $message]);
    }
}
