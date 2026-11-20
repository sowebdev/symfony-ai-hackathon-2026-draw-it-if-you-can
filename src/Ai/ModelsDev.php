<?php

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\ModelsDev\DataLoader;
use Symfony\AI\Platform\Bridge\ModelsDev\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The catalog of models.dev (an open registry of models: family, release date, price, capabilities),
 * for the game's three cloud providers. A default extract is versioned (data/models-dev.json); an
 * update, by `make install` or `make models-dev`, writes var/models-dev.json, which is read instead
 * when it exists. The game never calls models.dev on its own: no request while playing (Sören,
 * 20/11/2026). To refresh the default before a release, copy var/models-dev.json into data/.
 *
 * It serves three things:
 * - the capabilities of each model, through the models.dev catalog of Symfony AI, before the
 *   catalog of the bridge, which is frozen in the release of Symfony AI;
 * - the cards of the models screen, one per family, from the cheapest;
 * - the model preselected: the middle of the provider's current range, by price, neither the
 *   cheapest nor the most expensive (Sören, 20/11/2026: Sonnet rather than Haiku, never Opus or Fable).
 *
 * Without any file (MODELS_DEV_FILE pointing nowhere), the game plays with the catalogs of the
 * bridges, without cards nor preselection.
 */
final class ModelsDev
{
    public const string SOURCE = 'https://models.dev/api.json';

    /** The extract versioned with the game, and the update of this installation, relative to the project. */
    private const string DEFAULT_FILE = 'data/models-dev.json';
    private const string UPDATED_FILE = 'var/models-dev.json';

    /** The providers of models.dev the game uses, by their id there (the same as ours). */
    private const array PROVIDERS = [Provider::Anthropic, Provider::OpenAi, Provider::Mistral];

    /** A family whose newest model is this much older than the provider's newest is not part of the current range. */
    private const int CURRENT_DAYS = 365;

    /** @var array<string, array<string, mixed>>|null what the file holds, per provider */
    private static ?array $data = null;
    private static ?string $loadedFrom = null;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * Downloads models.dev and keeps the game's providers, in the format of models.dev.
     *
     * @return int the number of models kept
     */
    public function update(float $timeout = 20): int
    {
        $all = $this->httpClient->request('GET', self::SOURCE, ['timeout' => $timeout, 'max_duration' => $timeout])->toArray();
        $kept = [];
        foreach (self::PROVIDERS as $provider) {
            if (\is_array($all[$provider->value]['models'] ?? null)) {
                $kept[$provider->value] = $all[$provider->value];
            }
        }
        if ([] === $kept) {
            throw new \RuntimeException('models.dev answered without the providers of the game.');
        }
        $path = self::updatedPath();
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        // Pretty, so that the default extract, copied from it, reads well in a diff.
        file_put_contents($path, json_encode($kept, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        self::forget();

        return array_sum(array_map(static fn (array $provider): int => \count($provider['models']), $kept));
    }

    /**
     * The file read: MODELS_DEV_FILE when set (the tests); else the update of this installation if
     * there is one, else the extract versioned with the game.
     */
    public static function path(?string $root = null): string
    {
        $root ??= \dirname(__DIR__, 2);
        $forced = $_SERVER['MODELS_DEV_FILE'] ?? $_ENV['MODELS_DEV_FILE'] ?? null;
        if (\is_string($forced) && '' !== $forced) {
            return self::absolute($forced, $root);
        }
        $updated = self::absolute(self::UPDATED_FILE, $root);

        return is_file($updated) ? $updated : self::absolute(self::DEFAULT_FILE, $root);
    }

    /**
     * Where an update writes: MODELS_DEV_FILE when set, else var/models-dev.json, never the versioned extract.
     */
    public static function updatedPath(?string $root = null): string
    {
        $root ??= \dirname(__DIR__, 2);
        $forced = $_SERVER['MODELS_DEV_FILE'] ?? $_ENV['MODELS_DEV_FILE'] ?? null;

        return self::absolute(\is_string($forced) && '' !== $forced ? $forced : self::UPDATED_FILE, $root);
    }

    private static function absolute(string $path, string $root): string
    {
        return str_starts_with($path, '/') ? $path : $root.'/'.$path;
    }

    /**
     * The catalog of Symfony AI on this file, with the model class of the provider's bridge; null
     * without the file or for a server of one's own.
     */
    public static function catalog(Provider $provider): ?ModelCatalogInterface
    {
        if ([] === self::models($provider)) {
            return null;
        }

        return new ModelCatalog($provider->value, self::path(), completionsModelClass: match ($provider) {
            Provider::Anthropic => Claude::class,
            Provider::Mistral => Mistral::class,
            default => Gpt::class,
        });
    }

    /**
     * One card per family, its newest model, from the cheapest family; every other model apart,
     * sorted by name. A model of unknown capabilities, that models.dev does not know, deprecated or of
     * another maker, is never a card.
     *
     * @param list<ModelChoice> $choices
     *
     * @return array{cards: list<ModelChoice>, others: list<ModelChoice>}
     */
    public static function arrange(Provider $provider, array $choices): array
    {
        $models = self::models($provider);
        /** @var array<string, ModelChoice> $newest */
        $newest = [];
        foreach ($choices as $choice) {
            $model = $models[$choice->name] ?? null;
            $family = $model['family'] ?? null;
            if ($choice->isKnown() && null !== $model && \is_string($family) && self::ownCurrent($provider, $model) && (!isset($newest[$family]) || self::newer($models, $choice->name, $newest[$family]->name))) {
                $newest[$family] = $choice;
            }
        }
        $cards = array_values($newest);
        usort($cards, static fn (ModelChoice $a, ModelChoice $b): int => [self::price($models[$a->name]), $a->name] <=> [self::price($models[$b->name]), $b->name]);
        $others = array_values(array_filter($choices, static fn (ModelChoice $choice): bool => !\in_array($choice, $cards, true)));
        usort($others, static fn (ModelChoice $a, ModelChoice $b): int => strnatcasecmp($a->name, $b->name));

        return ['cards' => $cards, 'others' => $others];
    }

    /**
     * The model to preselect among those the provider lists: the middle of its current range (the
     * cheaper of the two middles); null when the file says nothing of them.
     *
     * @param list<string> $listed
     */
    public static function recommended(Provider $provider, array $listed): ?string
    {
        $range = self::range($provider, $listed);

        return [] === $range ? null : $range[intdiv(\count($range) - 1, 2)];
    }

    /**
     * The current range of the provider, from the cheapest: per family, its newest listed model that
     * reads images and calls tools, is not deprecated, is not of another maker (Mistral also serves
     * DeepSeek and GLM; many entries name no maker), and is less than a year older than the newest model of the provider (not
     * today: an old file still gives the same answer).
     *
     * @param list<string> $listed
     *
     * @return list<string>
     */
    public static function range(Provider $provider, array $listed): array
    {
        $models = self::models($provider);
        $dates = array_filter(array_map(static fn (array $model): string => (string) ($model['release_date'] ?? ''), $models));
        if ([] === $dates) {
            return [];
        }
        $since = (new \DateTimeImmutable(max($dates)))->modify(\sprintf('-%d days', self::CURRENT_DAYS))->format('Y-m-d');
        $newest = [];
        foreach ($listed as $id) {
            $model = $models[$id] ?? null;
            if (null === $model || !self::ownCurrent($provider, $model) || !\in_array('image', $model['modalities']['input'] ?? [], true)
                || true !== ($model['tool_call'] ?? false) || null === self::price($model) || ($model['release_date'] ?? '') < $since) {
                continue;
            }
            $family = \is_string($model['family'] ?? null) ? $model['family'] : $id;
            if (!isset($newest[$family]) || self::newer($models, $id, $newest[$family])) {
                $newest[$family] = $id;
            }
        }
        $range = array_values($newest);
        usort($range, static fn (string $a, string $b): int => [self::price($models[$a]), $a] <=> [self::price($models[$b]), $b]);

        return $range;
    }

    /**
     * What the file says of the provider's models, by id; empty without the file.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function models(Provider $provider): array
    {
        $path = self::path();
        if (null === self::$data || self::$loadedFrom !== $path) {
            self::$loadedFrom = $path;
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$data = \is_array($decoded) ? $decoded : [];
        }
        $models = self::$data[$provider->value]['models'] ?? [];

        return \is_array($models) ? $models : [];
    }

    /**
     * Forgets what was read, here and in the loader of Symfony AI: the file has changed.
     */
    public static function forget(): void
    {
        self::$data = null;
        DataLoader::clearCache();
    }

    /**
     * Not deprecated, and made by the provider: Mistral also serves DeepSeek and GLM (many entries name no maker).
     *
     * @param array<string, mixed> $model
     */
    private static function ownCurrent(Provider $provider, array $model): bool
    {
        $maker = explode('/', (string) ($model['canonical_model_id'] ?? ''))[0];

        return 'deprecated' !== ($model['status'] ?? null) && \in_array($maker, ['', $provider->value], true);
    }

    /**
     * @param array<string, mixed> $model
     */
    private static function price(array $model): ?float
    {
        $output = $model['cost']['output'] ?? null;

        return \is_int($output) || \is_float($output) ? (float) $output : null;
    }

    /**
     * Whether a is newer than b: by release date, then the shorter id (the alias, not the dated copy).
     *
     * @param array<string, array<string, mixed>> $models
     */
    private static function newer(array $models, string $a, string $b): bool
    {
        return [$models[$a]['release_date'] ?? '', -\strlen($a)] > [$models[$b]['release_date'] ?? '', -\strlen($b)];
    }
}
