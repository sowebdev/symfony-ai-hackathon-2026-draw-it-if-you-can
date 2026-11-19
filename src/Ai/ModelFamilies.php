<?php

namespace App\Ai;

/**
 * The families of each provider, from the cheapest to the most expensive, matched on the model name.
 * No list of models carries prices (checked for OpenAI on 19/11/2026), so the order is kept here by
 * hand, from the official price lists of 19/11/2026 (Anthropic, per million tokens of input/output:
 * Haiku 5.5 $0.10/$0.50, Sonnet 5.5 $2/$10, Opus 5.5 $4/$20, Fable 5.2 $8/$40).
 *
 * TO REVIEW whenever a provider adds a family (a name in a list that no row below knows): it then
 * lands among "all the models" until it gets its place here. Another OpenAI-compatible service has
 * no table: nothing is preselected there.
 */
final class ModelFamilies
{
    /** An empty name is the family of everything else: the models of OpenAI that are neither nano nor mini. */
    private const array TABLE = [
        'anthropic' => ['haiku', 'sonnet', 'opus', 'fable', 'mythos'],
        'openai' => ['nano', 'mini', ''],
        'mistral' => ['ministral', 'small', 'medium', 'large'],
    ];

    /**
     * Where the family of a model stands, 0 being the cheapest; null for a name of no known family,
     * and for a provider without a table.
     */
    public static function rank(Provider $provider, string $name): ?int
    {
        foreach (self::TABLE[$provider->value] ?? [] as $rank => $family) {
            if ('' === $family || 1 === preg_match('/(?<![a-z])'.$family.'(?![a-z])/i', $name)) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * One card per family, its most recent version, from the cheapest family; every other model
     * apart, sorted by name.
     *
     * @param list<ModelChoice> $choices
     *
     * @return array{cards: list<ModelChoice>, others: list<ModelChoice>}
     */
    public static function arrange(Provider $provider, array $choices): array
    {
        /** @var array<int, ModelChoice> $newest */
        $newest = [];
        foreach ($choices as $choice) {
            $rank = self::rank($provider, $choice->name);
            if (null !== $rank && (!isset($newest[$rank]) || self::newer($choice, $newest[$rank]))) {
                $newest[$rank] = $choice;
            }
        }
        ksort($newest);
        $cards = array_values($newest);
        $others = array_values(array_filter($choices, static fn (ModelChoice $choice): bool => !\in_array($choice, $cards, true)));
        usort($others, static fn (ModelChoice $a, ModelChoice $b): int => strnatcasecmp($a->name, $b->name));

        return ['cards' => $cards, 'others' => $others];
    }

    /**
     * The model to preselect: the most recent of the cheapest family present, null when no name is of a known family.
     *
     * @param list<ModelChoice> $choices
     */
    public static function cheapest(Provider $provider, array $choices): ?ModelChoice
    {
        return self::arrange($provider, $choices)['cards'][0] ?? null;
    }

    /**
     * Whether a is more recent than b: by creation date when both have one, else by the version
     * number in the name; then the shorter name (the alias, not the dated copy).
     */
    private static function newer(ModelChoice $a, ModelChoice $b): bool
    {
        if (null !== $a->created && null !== $b->created && $a->created !== $b->created) {
            return $a->created > $b->created;
        }
        $versions = self::compare(self::version($a->name), self::version($b->name));
        if (0 !== $versions) {
            return $versions > 0;
        }

        return [\strlen($a->name), $a->name] < [\strlen($b->name), $b->name];
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compare(array $a, array $b): int
    {
        // Number by number ([5] is above [4, 1]); PHP's own comparison of arrays starts with their length.
        for ($i = 0; $i < max(\count($a), \count($b)); ++$i) {
            if (($a[$i] ?? 0) !== ($b[$i] ?? 0)) {
                return ($a[$i] ?? 0) <=> ($b[$i] ?? 0);
            }
        }

        return 0;
    }

    /**
     * The numbers of the name, without a date: "claude-haiku-4-5-20251001" is 4.5.
     *
     * @return list<int>
     */
    private static function version(string $name): array
    {
        $name = (string) preg_replace('/(?<!\d)(\d{8}|\d{4}-\d{2}-\d{2}|\d{4})(?!\d)/', ' ', $name);
        preg_match_all('/\d+/', $name, $numbers);

        return array_map('intval', $numbers[0]);
    }
}
