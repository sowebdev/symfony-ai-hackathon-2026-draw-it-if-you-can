<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelChoice;
use App\Ai\ModelFamilies;
use App\Ai\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelFamiliesTest extends TestCase
{
    /**
     * @param list<string> $names
     *
     * @return list<ModelChoice>
     */
    private static function choices(array $names): array
    {
        return array_map(static fn (string $name): ModelChoice => new ModelChoice($name, true, true), $names);
    }

    /**
     * @return iterable<string, array{Provider, list<string>, ?string}>
     */
    public static function lists(): iterable
    {
        yield 'Anthropic, the list of 19/11/2026' => [Provider::Anthropic, ['claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-haiku-4-5-20251001', 'claude-3-5-haiku-20241022'], 'claude-haiku-5-5'];
        yield 'Anthropic without a Haiku' => [Provider::Anthropic, ['claude-opus-5-5', 'claude-sonnet-4-5-20250929', 'claude-sonnet-5-5'], 'claude-sonnet-5-5'];
        yield 'OpenAI: nano before mini before the rest' => [Provider::OpenAi, ['gpt-5.5', 'gpt-5-mini', 'gpt-5-nano', 'gpt-4.1-nano', 'gpt-4o'], 'gpt-5-nano'];
        yield 'OpenAI: a dated copy is not newer than its alias' => [Provider::OpenAi, ['gpt-5-mini-2025-08-07', 'gpt-5-mini', 'gpt-4.1-mini'], 'gpt-5-mini'];
        yield 'Mistral' => [Provider::Mistral, ['mistral-large-latest', 'mistral-medium-3.5', 'mistral-small-latest', 'ministral-8b-latest', 'ministral-3b-latest', 'codestral-latest'], 'ministral-8b-latest'];
        yield 'Mistral without a known family' => [Provider::Mistral, ['codestral-latest', 'pixtral-12b'], null];
        yield 'another service has no table' => [Provider::Compatible, ['qwen3.8:27b', 'haiku'], null];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('lists')]
    public function testTheCheapestFamilyAndItsMostRecentVersionIsPreselected(Provider $provider, array $names, ?string $expected): void
    {
        self::assertSame($expected, ModelFamilies::cheapest($provider, self::choices($names))?->name);
    }

    public function testTheCreationDateDecidesWhenTheListHasOne(): void
    {
        $choices = [new ModelChoice('claude-haiku-4-5', true, true, 1_800_000_000), new ModelChoice('claude-haiku-5-5', true, true, 1_700_000_000)];

        self::assertSame('claude-haiku-4-5', ModelFamilies::cheapest(Provider::Anthropic, $choices)?->name);
    }

    public function testOneCardPerFamilyFromTheCheapestAndTheRestApart(): void
    {
        $arranged = ModelFamilies::arrange(Provider::Anthropic, self::choices(['claude-opus-5-5', 'claude-haiku-4-5-20251001', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-brand-new-9']));

        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['cards']));
        self::assertSame(['claude-brand-new-9', 'claude-haiku-4-5-20251001'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['others']));
    }

    public function testAnotherServerHasNoCards(): void
    {
        $arranged = ModelFamilies::arrange(Provider::Compatible, self::choices(['b', 'a']));

        self::assertSame([], $arranged['cards']);
        self::assertSame(['a', 'b'], array_map(static fn (ModelChoice $c): string => $c->name, $arranged['others']));
    }
}
