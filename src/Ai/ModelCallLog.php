<?php

namespace App\Ai;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One line per call to a model, in the "ai" channel (var/log/ai.log, one JSON object a line): the role, where it
 * answered, how long it took, its tokens and what they cost, at the prices of models.dev. Never the messages, the
 * answer or the key. `app:ai:usage` adds them up.
 */
final readonly class ModelCallLog
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.ai')]
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function record(Role $role, RoleSetup $setup, float $seconds, ?TokenUsageInterface $usage, ?\Throwable $failure = null): void
    {
        $context = [
            'role' => $role->value,
            'provider' => $setup->provider->value,
            'model' => $setup->model,
            'ms' => (int) round($seconds * 1000),
            'input_tokens' => $usage?->getPromptTokens(),
            'output_tokens' => $usage?->getCompletionTokens(),
            'thinking_tokens' => $usage?->getThinkingTokens(),
            'cache_read_tokens' => $usage?->getCacheReadTokens(),
            'cache_write_tokens' => $usage?->getCacheCreationTokens(),
            'cost_usd' => null === $usage ? null : self::cost($setup->provider, ModelsDev::models($setup->provider)[$setup->model]['cost'] ?? null, $usage),
            'error' => null === $failure ? null : $failure::class.': '.mb_substr($failure->getMessage(), 0, 200),
        ];
        if (null === $failure) {
            $this->logger->info('model call', $context);
        } else {
            $this->logger->warning('model call failed', $context);
        }
    }

    /**
     * What the tokens cost, in dollars, at prices per million tokens; null without a price (a server of one's own).
     * Anthropic counts its input without what was read from or written to the cache; the others count it in.
     *
     * @param array<string, mixed>|null $price models.dev: input, output, cache_read, cache_write
     */
    public static function cost(Provider $provider, ?array $price, TokenUsageInterface $usage): ?float
    {
        if (null === $price || !isset($price['input'], $price['output'])) {
            return null;
        }
        $read = $usage->getCacheReadTokens() ?? 0;
        $written = $usage->getCacheCreationTokens() ?? 0;
        $input = $usage->getPromptTokens() ?? 0;
        if (Provider::Anthropic !== $provider) {
            $input = max(0, $input - $read - $written);
        }
        $output = ($usage->getCompletionTokens() ?? 0) + ($usage->getThinkingTokens() ?? 0);
        $dollars = $input * (float) $price['input'] + $output * (float) $price['output']
            + $read * (float) ($price['cache_read'] ?? $price['input']) + $written * (float) ($price['cache_write'] ?? $price['input']);

        return round($dollars / 1_000_000, 6);
    }
}
