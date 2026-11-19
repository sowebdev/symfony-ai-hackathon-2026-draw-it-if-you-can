<?php

namespace App\Ai;

use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Whether each role can play, for the home page: the checks of make check, kept a few minutes so
 * that the home page does not call the models at each visit. A change of model or endpoint is a
 * new check. A role that follows another is not checked twice.
 */
final readonly class ModelHealth
{
    /** A role that plays is checked again after this. */
    private const int HEALTHY_TTL_S = 300;

    /** A role that does not play is checked again soon: the model may be loading. */
    private const int SICK_TTL_S = 10;

    /** A home page request has 30 s for all its roles. */
    public const int ROLE_BUDGET_S = Models::REQUEST_LIMIT_S >> 1;

    public function __construct(
        private Models $models,
        private ModelSettings $settings,
        private ModelCheck $check,
        private CacheInterface $cache,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * One entry per role, in the order of the roles. A role that follows another has no check of its
     * own: it calls the same model, so it has the result of the role it follows.
     *
     * @return list<array{role: string, model: string, ok: bool, problem: ?string, follows: ?string}> Empty when no model is chosen yet
     */
    public function report(bool $fresh = false, ?int $roleBudgetS = self::ROLE_BUDGET_S): array
    {
        $report = [];
        if (!$this->settings->isStored()) {
            return $report;
        }
        $results = [];
        foreach (Role::cases() as $role) {
            $setup = $this->models->setup($role);
            $follows = $this->settings->follows($role);
            $model = $this->translator->trans($setup->provider->hasAddress() ? 'label.model_at' : 'label.model_at_provider', ['%model%' => $setup->model, '%address%' => $setup->place(), '%provider%' => $this->translator->trans('provider.'.$setup->provider->value)]);
            if (null !== $follows && isset($results[$follows->value])) {
                $report[] = ['role' => $role->value, 'model' => $model, 'ok' => $results[$follows->value]['ok'], 'problem' => $results[$follows->value]['problem'], 'follows' => $follows->value];
                continue;
            }
            $key = $this->key($role);
            if ($fresh) {
                $this->cache->delete($key);
            }
            // Kept as an id and its parameters, not as a sentence: the language is the one of whoever looks.
            $problem = $this->cache->get($key, function (ItemInterface $item) use ($role, $roleBudgetS): ?array {
                try {
                    $problem = $this->check->check($role, $roleBudgetS);
                } catch (\Throwable $exception) {
                    $setup = $this->models->setup($role);
                    $problem = self::explain($exception, $setup->provider, $setup->reasoningOff);
                }
                $item->expiresAfter(null === $problem ? self::HEALTHY_TTL_S : self::SICK_TTL_S);

                return $problem?->toArray();
            });
            $results[$role->value] = ['ok' => null === $problem, 'problem' => null === $problem ? null : Problem::fromArray($problem)->trans($this->translator)];
            $report[] = ['role' => $role->value, 'model' => $model, 'ok' => $results[$role->value]['ok'], 'problem' => $results[$role->value]['problem'], 'follows' => null];
        }

        return $report;
    }

    /**
     * The report as the home page shows it: one line per role with a model of its own, and one line for
     * "it draws and guesses" when the guesser follows the drawer.
     *
     * @param list<array{role: string, model: string, ok: bool, problem: ?string, follows: ?string}> $report
     *
     * @return list<array{role: string, model: string, ok: bool, problem: ?string}>
     */
    public static function lines(array $report): array
    {
        $followed = array_column(array_filter($report, static fn (array $entry): bool => null !== $entry['follows']), 'follows');
        $lines = [];
        foreach ($report as $entry) {
            if (null !== $entry['follows']) {
                continue;
            }
            $lines[] = [
                'role' => \in_array($entry['role'], $followed, true) && Role::Drawer->value === $entry['role'] ? 'both' : $entry['role'],
                'model' => $entry['model'],
                'ok' => $entry['ok'],
                'problem' => $entry['problem'],
            ];
        }

        return $lines;
    }

    /**
     * The models screen has just checked both models of the setting it saves: the home page shows
     * that result, without calling the models again.
     */
    public function rememberHealthy(): void
    {
        foreach (Role::cases() as $role) {
            $key = $this->key($role);
            $this->cache->delete($key);
            $this->cache->get($key, static function (ItemInterface $item): ?array {
                $item->expiresAfter(self::HEALTHY_TTL_S);

                return null;
            });
        }
    }

    private function key(Role $role): string
    {
        return 'model_health.'.$role->value.'.'.hash('xxh3', $this->models->fingerprint($role));
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
        if (Models::isTooSlow($exception)) {
            return new Problem('problem.slow', advice: $hasSwitch ? ($switchOff ? 'advice.reasoning_choose' : 'advice.reasoning_tick') : 'advice.reasoning_choose');
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
