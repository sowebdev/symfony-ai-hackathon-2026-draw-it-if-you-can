<?php

namespace App\Command;

use App\Ai\DrawsStrokes;
use App\Ai\Guesser;
use App\Ai\Models;
use App\Ai\Role;
use App\Drawing\GridStroke;
use App\Drawing\StrokeRenderer;
use App\Drawing\StrokeTracer;
use App\Game\Language;
use App\Game\Theme;
use App\Game\WordMatcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Draws words as in a round where nobody finds them: a first sketch, then passes of details, each
 * told the wrong answers of the guesser so far. Writes every step as an image, with the strokes
 * added and the guesses, to a page under var/bench, so that drawers and prompts can be compared.
 */
#[AsCommand(name: 'app:draw', description: 'Has the drawing AI draw words, pass after pass, and writes the steps to a page in var/bench')]
final readonly class DrawCommand
{
    /** Guesses at each step, as the guesser of the game says two words per state of the drawing. */
    private const int GUESSES_PER_STEP = 2;

    public function __construct(
        private DrawsStrokes $drawer,
        private Guesser $guesser,
        private StrokeTracer $tracer,
        private StrokeRenderer $renderer,
        private WordMatcher $matcher,
        private Models $models,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/bench')]
        private string $benchDir,
    ) {
    }

    /**
     * @param list<string> $words
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The words to draw')] array $words,
        #[Option('The theme the words belong to')] Theme $theme = Theme::Animals,
        #[Option('The language of the words and the guesses')] Language $language = Language::French,
        #[Option('Passes of details after the first sketch')] int $passes = 3,
        #[Option('Have the guesser name each step, and tell its wrong answers to the drawer')] bool $guess = true,
    ): int {
        $dir = $this->benchDir.'/'.date('Ymd-His');
        $drawer = $this->models->describe(Role::Drawer);
        $guesser = $guess ? $this->models->describe(Role::Guesser) : null;
        $io->title("Drawer {$drawer}".(null !== $guesser ? ", guesser {$guesser}" : ''));

        $sheets = [];
        foreach ($words as $word) {
            $sheets[] = $this->drawWord($io, $word, $theme, $language, $passes, $guess, $dir);
        }

        $this->filesystem->dumpFile($dir.'/index.html', $this->page($drawer, $guesser, $theme, $sheets));
        $io->success("Written to {$dir}/index.html");

        return Command::SUCCESS;
    }

    /**
     * @return array{word: string, steps: list<array{image: string, parts: list<string>, guesses: list<string>, found: bool, seconds: float}>}
     */
    private function drawWord(SymfonyStyle $io, string $word, Theme $theme, Language $language, int $passes, bool $guess, string $dir): array
    {
        $io->section($word);
        $drawn = [];
        $said = [];
        $steps = [];
        for ($pass = 0; $pass <= $passes; ++$pass) {
            $started = microtime(true);
            $added = [];
            foreach ($this->drawer->draw($word, $theme, $drawn, $said) as $stroke) {
                $added[] = $stroke;
            }
            $seconds = round(microtime(true) - $started, 1);
            if ([] === $added && [] !== $drawn) {
                $io->writeln("  pass {$pass}: nothing more to add");
                break;
            }
            $drawn = [...$drawn, ...$added];

            $png = $this->render($drawn);
            $image = \sprintf('%s-%d.png', preg_replace('/[^a-z0-9]+/', '-', strtolower($word)), $pass);
            $this->filesystem->dumpFile($dir.'/'.$image, $png);

            $guesses = [];
            $found = false;
            for ($i = 0; $guess && !$found && $i < self::GUESSES_PER_STEP; ++$i) {
                $answer = $this->guesser->guess($png, $theme, $language, $said);
                if (null === $answer) {
                    break;
                }
                $guesses[] = $answer;
                $found = $this->matcher->matches($answer, $word);
                if (!$found) {
                    $said[] = $answer;
                }
            }

            $parts = array_map(static fn (GridStroke $stroke): string => '' === $stroke->part ? '(no name)' : $stroke->part, $added);
            $steps[] = ['image' => $image, 'parts' => $parts, 'guesses' => $guesses, 'found' => $found, 'seconds' => $seconds];
            $io->writeln(\sprintf('  pass %d: %d strokes in %.1f s (%s)%s', $pass, \count($added), $seconds, implode(', ', $parts), [] === $guesses ? '' : ' → '.implode(', ', $guesses).($found ? ' ✓' : '')));
            if ($found) {
                break;
            }
        }

        return ['word' => $word, 'steps' => $steps];
    }

    /**
     * @param list<GridStroke> $strokes
     */
    private function render(array $strokes): string
    {
        $points = [];
        foreach ($strokes as $i => $stroke) {
            $points[] = $this->tracer->draw($stroke, 0, $i + 1)->points;
        }

        return $this->renderer->toPng($points);
    }

    /**
     * @param list<array{word: string, steps: list<array{image: string, parts: list<string>, guesses: list<string>, found: bool, seconds: float}>}> $sheets
     */
    private function page(string $drawer, ?string $guesser, Theme $theme, array $sheets): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES);
        $html = '';
        foreach ($sheets as ['word' => $word, 'steps' => $steps]) {
            $html .= '<section><h2>'.$e($word).'</h2><div class="steps">';
            foreach ($steps as $n => $step) {
                $html .= \sprintf(
                    '<figure class="%s"><img src="%s" alt=""><figcaption><b>%s</b> · %.1f s<br>+ %s%s</figcaption></figure>',
                    $step['found'] ? 'found' : '',
                    $e($step['image']),
                    0 === $n ? 'sketch' : 'details '.$n,
                    $step['seconds'],
                    $e(implode(', ', $step['parts'])),
                    [] === $step['guesses'] ? '' : '<br>→ '.$e(implode(', ', $step['guesses'])),
                );
            }
            $html .= '</div></section>';
        }
        $title = $e("Drawer {$drawer}".(null !== $guesser ? ", guesser {$guesser}" : '').", theme {$theme->value}, ".date('Y-m-d H:i'));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="utf-8"><title>{$title}</title>
            <style>
            body { font-family: sans-serif; margin: 2rem; color: #222; }
            .steps { display: flex; flex-wrap: wrap; gap: 1rem; }
            figure { margin: 0; width: 256px; }
            img { width: 256px; height: 256px; border: 1px solid #ccc; }
            figcaption { font-size: 0.85rem; }
            .found img { border: 3px solid #2f9e44; }
            </style></head>
            <body><h1>{$title}</h1>{$html}</body></html>
            HTML;
    }
}
