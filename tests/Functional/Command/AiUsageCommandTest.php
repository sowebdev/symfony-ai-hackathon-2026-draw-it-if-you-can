<?php

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The calls to the models, added up from the log: per role and model, with their cost.
 */
final class AiUsageCommandTest extends KernelTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = self::bootKernel()->getLogDir().'/ai.log';
        $line = static fn (string $role, string $provider, string $model, int $ms, int $in, int $out, ?float $cost, ?string $error = null, string $at = '2026-11-21T10:00:00+00:00'): string => json_encode([
            'message' => 'model call',
            'context' => ['role' => $role, 'provider' => $provider, 'model' => $model, 'ms' => $ms, 'input_tokens' => $in, 'output_tokens' => $out, 'thinking_tokens' => null, 'cache_read_tokens' => 1000, 'cache_write_tokens' => null, 'cost_usd' => $cost, 'error' => $error],
            'datetime' => $at,
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($this->file, implode("\n", [
            $line('drawer', 'anthropic', 'claude-sonnet-5-5', 4000, 500, 300, 0.004),
            $line('drawer', 'anthropic', 'claude-sonnet-5-5', 6000, 500, 300, 0.004),
            $line('judge', 'openai_compatible', 'qwen', 300, 1200, 2, null),
            $line('judge', 'openai_compatible', 'qwen', 8000, 1200, 0, null, 'TimeoutException: too slow'),
            $line('guesser', 'anthropic', 'claude-sonnet-5-5', 900, 100, 2, 0.001, at: '2026-11-20T10:00:00+00:00'),
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testItAddsUpTheCallsPerRoleAndModel(): void
    {
        $tester = $this->usage([]);

        self::assertMatchesRegularExpression('/drawer · claude-sonnet-5-5\s+2\s+5\.0 s\s+6\.0 s\s+3,000 \/ 600\s+\$0\.0080/', $tester->getDisplay(), 'Anthropic: the cache counts in the input');
        self::assertMatchesRegularExpression('/judge · qwen\s+2 \(1 failed\)\s+4\.2 s\s+8\.0 s\s+2,400 \/ 2\s+-/', $tester->getDisplay(), 'The others: the cache is already in it');
        self::assertStringContainsString('Total: $0.0090', $tester->getDisplay());
    }

    public function testItKeepsTheCallsOfAPeriod(): void
    {
        $tester = $this->usage(['--since' => '2026-11-21']);

        self::assertStringNotContainsString('guesser', $tester->getDisplay());
        self::assertStringContainsString('Total: $0.0080', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $input
     */
    private function usage(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::getContainer()->get(KernelInterface::class)))->find('app:ai:usage'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
