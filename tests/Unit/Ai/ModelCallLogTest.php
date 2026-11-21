<?php

namespace App\Tests\Unit\Ai;

use App\Ai\MeteredPlatform;
use App\Ai\ModelCallLog;
use App\Ai\Provider;
use App\Ai\Role;
use App\Ai\RoleSetup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Each call to a model is one line of the log, with what its tokens cost; never what was said.
 */
final class ModelCallLogTest extends TestCase
{
    private const array SONNET = ['input' => 2, 'output' => 10, 'cache_read' => 0.1, 'cache_write' => 2.5];

    public function testAnthropicCountsItsInputWithoutTheCache(): void
    {
        $usage = new TokenUsage(promptTokens: 1000, completionTokens: 200, cacheCreationTokens: 2000, cacheReadTokens: 10_000);

        // 1000 x 2 + 200 x 10 + 10 000 x 0.1 + 2000 x 2.5, per million tokens.
        self::assertSame(0.01, ModelCallLog::cost(Provider::Anthropic, self::SONNET, $usage));
    }

    public function testTheOthersCountTheCacheInTheirInput(): void
    {
        $usage = new TokenUsage(promptTokens: 1000, completionTokens: 100, cachedTokens: 800, cacheReadTokens: 800);

        // (1000 - 800) x 2 + 100 x 10 + 800 x 0.1.
        self::assertSame(0.00148, ModelCallLog::cost(Provider::OpenAi, self::SONNET, $usage));
    }

    public function testAModelWithoutAPriceCostsNothingKnown(): void
    {
        self::assertNull(ModelCallLog::cost(Provider::Compatible, null, new TokenUsage(promptTokens: 10)));
    }

    public function testACallIsLoggedWithItsRoleAndNeverItsMessages(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $setup = new RoleSetup(Provider::Compatible, 'http://scripted:8000', null, 'scripted', true);
        $platform = new MeteredPlatform(MockPlatformFactory::createPlatform('cat'), Role::Judge, $setup, new ModelCallLog($logger));

        self::assertSame('cat', $platform->invoke('scripted', new MessageBag(Message::ofUser('a secret prompt')))->asText());

        self::assertCount(1, $logger->lines);
        [$level, $message, $context] = $logger->lines[0];
        self::assertSame(['info', 'model call'], [$level, $message]);
        self::assertSame(['judge', 'openai_compatible', 'scripted'], [$context['role'], $context['provider'], $context['model']]);
        self::assertIsInt($context['ms']);
        self::assertStringNotContainsString('secret', (string) json_encode($logger->lines));
    }
}
