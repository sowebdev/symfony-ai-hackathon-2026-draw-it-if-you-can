<?php

namespace App\Ai;

use Symfony\AI\Platform\Capability;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The models of a provider, from its GET /v1/models, crossed with the catalog of its bridge, or with
 * the capabilities the answer carries: only the models that cannot play are left out.
 */
final readonly class ModelLister
{
    /** Seconds without an answer, then the most a whole list may take (Anthropic pages). */
    private const int TIMEOUT_S = 3;
    private const int MAX_DURATION_S = 6;
    private const int MAX_PAGES = 10;
    private const string ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    /**
     * Never throws for a provider that fails: the problem is in the list, and the caller lets the
     * player type a model name.
     *
     * @param bool $fresh Forget the list kept in the cache
     */
    public function list(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, bool $fresh = false): ModelList
    {
        // The key is a hash in the key of the cache: a list is for a key, never the key itself.
        $cacheKey = \sprintf('model_list.v5.%s.%s', $provider->value, substr(hash('sha256', $provider->addressOr($address)."\0".$apiKey), 0, 32));
        if ($fresh) {
            $this->cache->delete($cacheKey);
        }

        /** @var array{choices: list<array{name: string, vision: ?bool, tools: ?bool, created: ?int}>, problem: ?array{id: string, parameters: array<string, string>, advice: ?string}} $found */
        $found = $this->cache->get($cacheKey, function (ItemInterface $item) use ($provider, $address, $apiKey): array {
            $item->expiresAfter($provider->listTtl());
            try {
                return ['choices' => array_map(static fn (ModelChoice $choice): array => $choice->toArray(), self::choices($provider, $this->fetch($provider, $address, $apiKey))), 'problem' => null];
            } catch (ModelListException $exception) {
                // A failure is not kept: the next call asks again.
                $item->expiresAfter(0);

                return ['choices' => [], 'problem' => $exception->problem->toArray()];
            }
        });
        if (null !== $found['problem']) {
            return ModelList::failed(Problem::fromArray($found['problem']));
        }

        return new ModelList(array_map(ModelChoice::fromArray(...), $found['choices']));
    }

    /**
     * What each entry of a GET /v1/models says it can do, as far as it says, sorted by name.
     *
     * - A provider with a catalog (Anthropic, OpenAI, Mistral): only the models that can play. The
     *   answer of Mistral carries capabilities (vision, function calling): when present they decide,
     *   else the catalog (models.dev, then the bridge's own). A model neither knows is not
     *   listed (embeddings, audio, images, or a model too new even for models.dev: type its name).
     * - The Generic case has no catalog: its entries are filtered when they carry capabilities in a
     *   known shape (OpenRouter, the Hugging Face router: `architecture.input_modalities`,
     *   `supported_parameters`), else listed as they are, capabilities unknown.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<ModelChoice>
     */
    public static function choices(Provider $provider, array $entries): array
    {
        $known = $provider->bridgeCatalog()?->getModels();
        $choices = [];
        foreach ($entries as $entry) {
            $name = $entry['id'];
            if (!\is_string($name) || '' === $name || isset($choices[$name])) {
                continue;
            }
            $choice = null === $known ? self::advertised($name, $entry) : (self::stated($name, $entry, $provider) ?? self::catalogued($name, $known));
            if (null !== $choice && $choice->canPlay(Role::Guesser)) {
                $choices[$name] = $choice->created(self::created($entry));
            }
        }
        uasort($choices, static fn (ModelChoice $a, ModelChoice $b): int => strnatcasecmp($a->name, $b->name));

        return array_values($choices);
    }

    /**
     * When an entry says it was created: `created_at` (Anthropic, an ISO date) or `created` (OpenAI,
     * Mistral, OpenRouter, a Unix time).
     *
     * @param array<string, mixed> $entry
     */
    private static function created(array $entry): ?int
    {
        $created = $entry['created_at'] ?? $entry['created'] ?? null;
        if (\is_int($created) && $created > 0) {
            return $created;
        }
        if (\is_string($created) && '' !== $created) {
            $time = strtotime($created);

            return false === $time ? null : $time;
        }

        return null;
    }

    /**
     * @param array<string, array{capabilities: list<Capability>}> $known
     */
    private static function catalogued(string $name, array $known): ?ModelChoice
    {
        if (!isset($known[$name])) {
            return null;
        }
        $capabilities = $known[$name]['capabilities'];

        return new ModelChoice($name, Capability::INPUT_IMAGE->equalsOneOf($capabilities), Capability::TOOL_CALLING->equalsOneOf($capabilities));
    }

    /**
     * What Mistral says in its own answer: `capabilities: {vision: true, function_calling: true, ...}`.
     *
     * @param array<string, mixed> $entry
     */
    private static function stated(string $name, array $entry, Provider $provider): ?ModelChoice
    {
        $capabilities = $entry['capabilities'] ?? null;
        if (Provider::Mistral !== $provider || !\is_array($capabilities) || !\array_key_exists('vision', $capabilities) && !\array_key_exists('function_calling', $capabilities)) {
            return null;
        }
        // An embedding or a moderation model says no to chat completion: nobody can play with it.
        $chat = $capabilities['completion_chat'] ?? true;

        return new ModelChoice($name, true === ($capabilities['vision'] ?? false) && false !== $chat, true === ($capabilities['function_calling'] ?? false) && false !== $chat);
    }

    /**
     * The entry of a server without a catalog: the capabilities it carries in a known shape, else none.
     *
     * @param array<string, mixed> $entry
     */
    private static function advertised(string $name, array $entry): ModelChoice
    {
        $architecture = \is_array($entry['architecture'] ?? null) ? $entry['architecture'] : [];
        $inputs = \is_array($architecture['input_modalities'] ?? null) ? $architecture['input_modalities'] : null;
        $outputs = \is_array($architecture['output_modalities'] ?? null) ? $architecture['output_modalities'] : null;
        $parameters = \is_array($entry['supported_parameters'] ?? null) ? $entry['supported_parameters'] : null;

        // An image or audio generator, an embedding: nothing it says it takes matters, it writes no text.
        if (null !== $outputs && !\in_array('text', $outputs, true)) {
            return new ModelChoice($name, false, false);
        }

        return new ModelChoice(
            $name,
            null === $inputs ? null : \in_array('image', $inputs, true),
            null === $parameters ? null : \in_array('tools', $parameters, true),
        );
    }

    /**
     * @return list<array<string, mixed>> The entries of the list: each has an "id"
     *
     * @throws ModelListException
     */
    private function fetch(Provider $provider, ?string $address, ?string $apiKey): array
    {
        $base = $provider->addressOr($address);
        if ('' === $base) {
            throw new ModelListException(new Problem('list.needs_address'));
        }
        if ($provider->needsKey() && (null === $apiKey || '' === $apiKey)) {
            throw new ModelListException(new Problem('list.needs_key', ['%provider%' => $provider->label()]));
        }

        $headers = Provider::Anthropic === $provider
            ? ['x-api-key' => (string) $apiKey, 'anthropic-version' => self::ANTHROPIC_VERSION]
            : (null === $apiKey || '' === $apiKey ? [] : ['Authorization' => 'Bearer '.$apiKey]);
        $deadline = microtime(true) + self::MAX_DURATION_S;
        $entries = [];
        $query = Provider::Anthropic === $provider ? ['limit' => 1000] : [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $body = $this->get($provider, $base, $base.'/v1/models', $query, $headers, $deadline);
            foreach ($body['data'] ?? [] as $model) {
                if (\is_array($model) && \is_string($model['id'] ?? null) && '' !== $model['id']) {
                    $entries[] = $model;
                }
            }
            // Anthropic pages: has_more, then the last id of the page is where the next starts.
            if (Provider::Anthropic !== $provider || true !== ($body['has_more'] ?? false) || !\is_string($body['last_id'] ?? null)) {
                break;
            }
            $query['after_id'] = $body['last_id'];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     *
     * @throws ModelListException
     */
    private function get(Provider $provider, string $base, string $url, array $query, array $headers, float $deadline): array
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new ModelListException(new Problem('list.too_long', ['%provider%' => $provider->label(), '%seconds%' => (string) self::MAX_DURATION_S]));
        }

        try {
            return $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => $headers + ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_S,
                'max_duration' => $left,
            ])->toArray();
        } catch (TimeoutExceptionInterface) {
            throw new ModelListException(new Problem('list.timeout', ['%provider%' => $provider->label(), '%seconds%' => (string) self::TIMEOUT_S]));
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getResponse()->getStatusCode();

            throw new ModelListException(match (true) {
                401 === $status || 403 === $status => new Problem('list.refused_key', ['%provider%' => $provider->label(), '%status%' => (string) $status]), 404 === $status => new Problem('list.not_found', ['%provider%' => $provider->label()]), 429 === $status => new Problem('list.too_many', ['%provider%' => $provider->label()]), default => new Problem('list.http_error', ['%provider%' => $provider->label(), '%status%' => (string) $status]),
            });
        } catch (TransportExceptionInterface) {
            throw new ModelListException(new Problem('list.no_answer', ['%where%' => $provider->hasAddress() ? $base : $provider->label()]));
        } catch (DecodingExceptionInterface) {
            throw new ModelListException(new Problem('list.not_a_list', ['%provider%' => $provider->label()]));
        }
    }
}
