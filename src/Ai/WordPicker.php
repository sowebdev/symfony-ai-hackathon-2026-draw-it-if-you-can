<?php

namespace App\Ai;

use App\Game\Language;
use App\Game\Theme;
use App\Game\WordMatcher;
use App\Repository\RoundRepository;
use Random\Randomizer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Picks the word the player will draw, in the theme and the player's language. The model lists
 * candidates, the code draws one at random: asked for a single word, a model always gives the
 * most likely one ("mouse" for the developer theme, seen on 10/11/2026).
 */
final readonly class WordPicker
{
    private const int ATTEMPTS = 3;
    private const int CANDIDATES = 15;

    /** Words of the last rounds of the same theme and language, left out of the draw. */
    private const int RECENT_ROUNDS = 30;

    public function __construct(
        private OneWordModel $model,
        private RoundRepository $rounds,
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    public function pick(Theme $theme, Language $language): string
    {
        $recent = $this->rounds->recentTargetWords($theme, $language, self::RECENT_ROUNDS);
        $avoid = [] === $recent ? '' : "\nThese words were drawn recently, list other ones: ".implode(', ', $recent).'.';
        $candidates = self::CANDIDATES;

        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You run a drawing and guessing game. List words the player could draw.
                Theme: {$theme->description()}.
                Each word is a concrete noun in {$language->nameInEnglish()}, something one can draw in less than a minute with a black pen, known to everyone.
                List {$candidates} different words, varied, not only the most obvious ones: one word per line, in lower case, without article, nothing else.{$avoid}
                PROMPT),
            Message::ofUser('List the words.'),
        );

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $word = self::draw($this->model->askList(Role::Guesser, $messages, 1.0), $recent, $this->randomizer);
            if (null !== $word) {
                return $word;
            }
        }

        throw new \RuntimeException(\sprintf('The model gave no usable word in %d attempts.', self::ATTEMPTS));
    }

    /**
     * A candidate at random, leaving out the recent words, unless the model listed nothing else.
     *
     * @param list<string> $candidates
     * @param list<string> $recent
     */
    public static function draw(array $candidates, array $recent, Randomizer $randomizer): ?string
    {
        $recent = array_map(WordMatcher::normalize(...), $recent);
        $fresh = array_values(array_filter(
            $candidates,
            static fn (string $word): bool => !\in_array(WordMatcher::normalize($word), $recent, true),
        ));
        $pool = [] === $fresh ? $candidates : $fresh;

        return [] === $pool ? null : $pool[$randomizer->pickArrayKeys($pool, 1)[0]];
    }
}
