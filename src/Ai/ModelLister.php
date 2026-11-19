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
 * The models of a provider, from its GET /v1/models, crossed with the catalog of its bridge: for
 * each role, those the catalog says can play it, then the others, untested.
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
        $cacheKey = \sprintf('model_list.%s.%s', $provider->value, substr(hash('sha256', $provider->addressOr($address)."\0".$apiKey), 0, 32));
        if ($fresh) {
            $this->cache->delete($cacheKey);
        }

        /** @var array{names: list<string>, problem: ?string} $found */
        $found = $this->cache->get($cacheKey, function (ItemInterface $item) use ($provider, $address, $apiKey): array {
            $item->expiresAfter($provider->listTtl());
            try {
                return ['names' => $this->fetch($provider, $address, $apiKey), 'problem' => null];
            } catch (ModelListException $exception) {
                // A failure is not kept: the next call asks again.
                $item->expiresAfter(0);

                return ['names' => [], 'problem' => $exception->getMessage()];
            }
        });
        if (null !== $found['problem']) {
            return ModelList::failed($found['problem']);
        }
        $names = $found['names'];

        return self::cross($provider, $names);
    }

    /**
     * @param list<string> $names
     */
    public static function cross(Provider $provider, array $names): ModelList
    {
        $known = $provider->bridgeCatalog()?->getModels();
        $guesser = $drawer = $others = [];
        foreach (array_unique($names) as $name) {
            if (null === $known || !isset($known[$name])) {
                $others[] = $name;
                continue;
            }
            $capabilities = $known[$name]['capabilities'];
            if (Capability::INPUT_IMAGE->equalsOneOf($capabilities)) {
                $guesser[] = $name;
                if (Capability::TOOL_CALLING->equalsOneOf($capabilities)) {
                    $drawer[] = $name;
                }
            }
        }

        return new ModelList($guesser, $drawer, $others);
    }

    /**
     * @return list<string>
     *
     * @throws ModelListException
     */
    private function fetch(Provider $provider, ?string $address, ?string $apiKey): array
    {
        $base = $provider->addressOr($address);
        if ('' === $base) {
            throw new ModelListException(\sprintf('%s needs the address of the server before its models can be listed.', $provider->label()));
        }
        if ($provider->needsKey() && (null === $apiKey || '' === $apiKey)) {
            throw new ModelListException(\sprintf('%s needs an API key before its models can be listed.', $provider->label()));
        }

        $headers = Provider::Anthropic === $provider
            ? ['x-api-key' => (string) $apiKey, 'anthropic-version' => self::ANTHROPIC_VERSION]
            : (null === $apiKey || '' === $apiKey ? [] : ['Authorization' => 'Bearer '.$apiKey]);
        $deadline = microtime(true) + self::MAX_DURATION_S;
        $names = [];
        $query = Provider::Anthropic === $provider ? ['limit' => 1000] : [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $body = $this->get($provider, $base, $base.'/v1/models', $query, $headers, $deadline);
            foreach ($body['data'] ?? [] as $model) {
                if (\is_array($model) && \is_string($model['id'] ?? null) && '' !== $model['id']) {
                    $names[] = $model['id'];
                }
            }
            // Anthropic pages: has_more, then the last id of the page is where the next starts.
            if (Provider::Anthropic !== $provider || true !== ($body['has_more'] ?? false) || !\is_string($body['last_id'] ?? null)) {
                break;
            }
            $query['after_id'] = $body['last_id'];
        }

        return array_values(array_unique($names));
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
            throw new ModelListException(\sprintf('%s took more than %d s to list its models.', $provider->label(), self::MAX_DURATION_S));
        }

        try {
            return $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => $headers + ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_S,
                'max_duration' => $left,
            ])->toArray();
        } catch (TimeoutExceptionInterface) {
            throw new ModelListException(\sprintf('%s did not answer within %d s.', $provider->label(), self::TIMEOUT_S));
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getResponse()->getStatusCode();

            throw new ModelListException(match (true) {
                401 === $status || 403 === $status => \sprintf('%s refused the API key (HTTP %d): check it.', $provider->label(), $status), 404 === $status => \sprintf('%s does not list its models at this address (HTTP 404): is the address right?', $provider->label()), 429 === $status => \sprintf('%s says too many requests (HTTP 429): try again in a moment.', $provider->label()), default => \sprintf('%s answered HTTP %d when asked for its models.', $provider->label(), $status),
            });
        } catch (TransportExceptionInterface) {
            throw new ModelListException(\sprintf('Nothing answers at %s: is the address right, is the server started?', $provider->hasAddress() ? $base : $provider->label()));
        } catch (DecodingExceptionInterface) {
            throw new ModelListException(\sprintf('%s did not answer with a list of models.', $provider->label()));
        }
    }
}
