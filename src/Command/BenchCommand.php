<?php

namespace App\Command;

use App\Ai\Drawer;
use App\Ai\ModelCallLog;
use App\Ai\Models;
use App\Ai\ModelSettings;
use App\Ai\PlatformFactory;
use App\Ai\Provider;
use App\Ai\Role;
use App\Ai\RoleSetup;
use App\Drawing\GridStroke;
use App\Drawing\StrokeRenderer;
use App\Drawing\StrokeTracer;
use App\Game\Theme;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The drawing bench of docs/bench: the same words drawn by the models of docs/bench/bench.json, one sketch
 * each, as the AI draws it at the start of a round. Only what is missing is drawn, so a run goes on where the
 * last one stopped, and a model that is not served now (a local model not loaded) is left for a later run.
 * The page docs/bench/README.md is written again from the results, without calling any model.
 */
#[AsCommand(name: 'app:bench', description: 'Has each model of docs/bench/bench.json draw its words, and writes the page docs/bench/README.md')]
final readonly class BenchCommand
{
    public function __construct(
        private ModelSettings $settings,
        private PlatformFactory $platforms,
        private StrokeTracer $tracer,
        private StrokeRenderer $renderer,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docs/bench')]
        private string $dir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only the models whose label contains this text')] ?string $only = null,
        #[Option('Draw again what is already drawn')] bool $again = false,
        #[Option('Write the page from the results, without drawing')] bool $pageOnly = false,
    ): int {
        $bench = json_decode($this->filesystem->readFile($this->dir.'/bench.json'), true, flags: \JSON_THROW_ON_ERROR);
        $resultsFile = $this->dir.'/results.json';
        $results = is_file($resultsFile) ? json_decode($this->filesystem->readFile($resultsFile), true, flags: \JSON_THROW_ON_ERROR) : [];

        foreach ($pageOnly ? [] : $bench['models'] as $entry) {
            if (null !== $only && !str_contains(strtolower($entry['label']), strtolower($only))) {
                continue;
            }
            $io->section($entry['label']);
            foreach ($bench['words'] as ['word' => $word, 'theme' => $theme]) {
                $key = self::slug($entry['label']).'/'.self::slug($word);
                if (!$again && isset($results[$key]) && !isset($results[$key]['error'])) {
                    $io->writeln("  {$word}: already drawn");
                    continue;
                }
                $results[$key] = $this->drawOne($entry, $word, Theme::from($theme), $key);
                $io->writeln(\sprintf('  %s: %s', $word, $results[$key]['error'] ?? \sprintf('%d strokes in %.1f s', $results[$key]['strokes'], $results[$key]['seconds'])));
                ksort($results);
                $this->filesystem->dumpFile($resultsFile, json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
            }
        }

        $this->filesystem->dumpFile($this->dir.'/README.md', $this->page($bench, $results));
        $io->success('Written to docs/bench/README.md');

        return Command::SUCCESS;
    }

    /**
     * @param array{label: string, provider: string, model: string, address?: string} $entry
     *
     * @return array<string, mixed>
     */
    private function drawOne(array $entry, string $word, Theme $theme, string $key): array
    {
        $provider = Provider::from($entry['provider']);
        $address = $entry['address'] ?? null;
        $setup = new RoleSetup($provider, $address, $this->settings->savedKeyFor($provider, $address), $entry['model'], $provider->reasoningOffByDefault($address));
        $calls = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };
        $models = new Models($this->settings, $this->platforms, [Role::Drawer->value => $setup], new ModelCallLog($calls));
        $drawer = new Drawer($models, $this->tracer, $this->renderer);

        $started = microtime(true);
        $strokes = [];
        try {
            foreach ($drawer->draw($word, $theme) as $stroke) {
                $strokes[] = $stroke;
            }
        } catch (\Throwable $failure) {
            return ['error' => mb_substr($failure->getMessage(), 0, 160), 'date' => date('Y-m-d')];
        }
        $seconds = round(microtime(true) - $started, 1);
        if ([] === $strokes) {
            return ['error' => 'no stroke', 'date' => date('Y-m-d')];
        }

        $points = [];
        foreach ($strokes as $i => $stroke) {
            $points[] = $this->tracer->draw($stroke, 0, $i + 1)->points;
        }
        $this->filesystem->dumpFile($this->dir.'/'.$key.'.png', $this->renderer->toPng($points));
        $costs = array_column($calls->records, 'cost_usd');

        return [
            'strokes' => \count($strokes),
            'seconds' => $seconds,
            'cost_usd' => [] === $costs || \in_array(null, $costs, true) ? null : round(array_sum($costs), 4),
            'parts' => array_values(array_filter(array_map(static fn (GridStroke $stroke): string => $stroke->part, $strokes))),
            'date' => date('Y-m-d'),
        ];
    }

    /**
     * @param array{words: list<array{word: string, theme: string}>, models: list<array{label: string, provider: string, model: string}>} $bench
     * @param array<string, array<string, mixed>>                                                                                         $results
     */
    private function page(array $bench, array $results): string
    {
        $words = array_column($bench['words'], 'word');
        $lines = [
            '# The drawing bench',
            '',
            'Each model draws the same words, one sketch each, as the AI draws at the start of a round, with nobody guessing. One try per model and word: an idea of what to expect, not a measure. The cost is that of the tokens, at the prices of [models.dev](https://models.dev); a model on your own machine costs nothing.',
            '',
            'Written by `bin/console app:bench` from [bench.json](bench.json) and [results.json](results.json): it draws only what is missing (`--again` to draw again, `--only=haiku` for one model), then writes this page without calling any model.',
            '',
            '| Model | '.implode(' | ', $words).' |',
            '|---|'.str_repeat('---|', \count($words)),
        ];
        foreach ($bench['models'] as $entry) {
            $cells = [];
            foreach ($words as $word) {
                $key = self::slug($entry['label']).'/'.self::slug($word);
                $result = $results[$key] ?? null;
                $cells[] = match (true) {
                    null === $result => 'not drawn yet',
                    isset($result['error']) => 'failed: '.str_replace('|', '/', $result['error']),
                    default => \sprintf(
                        '<img src="%s.png" width="160" alt="%s by %s"><br>%d strokes, %.1f s%s',
                        $key,
                        $word,
                        $entry['label'],
                        $result['strokes'],
                        $result['seconds'],
                        match (true) {
                            null === $result['cost_usd'] => '',
                            $result['cost_usd'] < 0.001 => ', < $0.001',
                            default => \sprintf(', $%.3f', $result['cost_usd']),
                        },
                    ),
                };
            }
            $lines[] = \sprintf('| **%s**<br>`%s` | %s |', $entry['label'], $entry['model'], implode(' | ', $cells));
        }
        $dates = array_unique(array_filter(array_column($results, 'date')));
        sort($dates);
        $lines[] = '';
        $lines[] = [] === $dates ? '' : 'Drawn on '.implode(', ', $dates).'.';

        return implode("\n", $lines)."\n";
    }

    private static function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }
}
