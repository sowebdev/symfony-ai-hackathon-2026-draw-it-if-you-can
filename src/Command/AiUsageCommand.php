<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds up the calls to the models written by ModelCallLog in var/log/ai.log: per role and model, how many, how
 * long, how many tokens and what they cost. Reads the file only: no model is called.
 */
#[AsCommand(name: 'app:ai:usage', description: 'Adds up the calls to the models: count, time, tokens and cost, per role and model')]
final readonly class AiUsageCommand
{
    public function __construct(
        #[Autowire('%kernel.logs_dir%/ai.log')]
        private string $file,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only the calls since this date or time, e.g. "today", "-1 hour" or "2026-11-21 10:00"')]
        ?string $since = null,
    ): int {
        if (!is_file($this->file)) {
            $io->writeln('No call to a model is logged yet ('.$this->file.').');

            return Command::SUCCESS;
        }
        $from = null === $since ? null : new \DateTimeImmutable($since);
        $rows = [];
        foreach (file($this->file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            $call = \is_array($entry) && \is_array($entry['context'] ?? null) ? $entry['context'] : null;
            if (null === $call || (null !== $from && new \DateTimeImmutable((string) $entry['datetime']) < $from)) {
                continue;
            }
            $key = $call['role'].' · '.$call['model'];
            $row = $rows[$key] ?? ['calls' => 0, 'failed' => 0, 'ms' => [], 'in' => 0, 'out' => 0, 'cost' => null];
            ++$row['calls'];
            $row['failed'] += null === $call['error'] ? 0 : 1;
            $row['ms'][] = (int) $call['ms'];
            // Anthropic counts its input without the cache, the others with it (ModelCallLog::cost()).
            $row['in'] += (int) $call['input_tokens'] + ('anthropic' === $call['provider'] ? (int) $call['cache_read_tokens'] + (int) $call['cache_write_tokens'] : 0);
            $row['out'] += (int) $call['output_tokens'] + (int) $call['thinking_tokens'];
            if (null !== $call['cost_usd']) {
                $row['cost'] = ($row['cost'] ?? 0.0) + (float) $call['cost_usd'];
            }
            $rows[$key] = $row;
        }
        if ([] === $rows) {
            $io->writeln('No call to a model in that period.');

            return Command::SUCCESS;
        }
        ksort($rows);

        $total = 0.0;
        $table = [];
        foreach ($rows as $key => $row) {
            sort($row['ms']);
            $total += $row['cost'] ?? 0.0;
            $table[] = [
                $key,
                $row['calls'].($row['failed'] > 0 ? " ({$row['failed']} failed)" : ''),
                self::seconds(array_sum($row['ms']) / \count($row['ms'])),
                self::seconds($row['ms'][max(0, (int) ceil(0.95 * \count($row['ms'])) - 1)]),
                number_format($row['in']).' / '.number_format($row['out']),
                null === $row['cost'] ? '-' : '$'.number_format($row['cost'], 4),
            ];
        }
        $io->table(['Role · model', 'Calls', 'Mean', '95 %', 'Tokens in / out', 'Cost'], $table);
        $io->writeln(\sprintf('Total: $%s (models.dev prices; "-" for a model without a price, such as one of your own).', number_format($total, 4)));

        return Command::SUCCESS;
    }

    private static function seconds(float $ms): string
    {
        return number_format($ms / 1000, 1).' s';
    }
}
