<?php

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals the API key before it reaches the database: libsodium's secretbox, a fresh nonce each
 * time, with the key of APP_ENCRYPTION_KEY (32 random bytes, base64). What it protects and what it
 * does not: docs/adr/0004-platforms-built-at-runtime-from-a-stored-setting.md.
 *
 * No message here, no exception, no log line ever contains the key or the text.
 */
final readonly class Encryption
{
    public const string HOW_TO_FIX = 'Generate a key with `php -r \'echo base64_encode(random_bytes(32)), PHP_EOL;\'` and put it in .env.local as APP_ENCRYPTION_KEY=<the key> (make install does it for you when it is missing).';

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire('%env(APP_ENCRYPTION_KEY)%')]
        private string $key = '',
    ) {
    }

    /**
     * Whether a usable key is set.
     */
    public function isConfigured(): bool
    {
        return null !== $this->rawKey();
    }

    /**
     * @return string base64 of the nonce followed by the sealed text
     *
     * @throws EncryptionException without a usable key
     */
    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $key = $this->requireKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);

        return base64_encode($nonce.$sealed);
    }

    /**
     * @throws EncryptionException without a usable key, with another key than the one that sealed the text, or on a text that was altered
     */
    public function decrypt(string $sealed): string
    {
        $key = $this->requireKey();
        $raw = base64_decode($sealed, true);
        if (false === $raw || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new EncryptionException('The stored API key is not readable: save the setting again with its API key.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        sodium_memzero($key);
        if (false === $plain) {
            throw new EncryptionException('The stored API key cannot be decrypted with the current APP_ENCRYPTION_KEY: it was saved with another one. Put the old key back, or save the setting again with its API key.');
        }

        return $plain;
    }

    private function requireKey(): string
    {
        if ('' === $this->key) {
            throw new EncryptionException('APP_ENCRYPTION_KEY is empty: the API key cannot be stored without it. '.self::HOW_TO_FIX);
        }

        return $this->rawKey() ?? throw new EncryptionException('APP_ENCRYPTION_KEY is not 32 bytes in base64. '.self::HOW_TO_FIX);
    }

    private function rawKey(): ?string
    {
        $raw = base64_decode($this->key, true);

        return \is_string($raw) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === \strlen($raw) ? $raw : null;
    }
}
