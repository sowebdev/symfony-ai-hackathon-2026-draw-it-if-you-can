<?php

namespace App\Ai;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The draft of the model screen, in the session. The API keys go there sealed, never in clear, and
 * never back to the browser.
 */
final readonly class DraftStore
{
    private const string SESSION_KEY = 'model_draft';

    public function __construct(
        private RequestStack $requests,
        private Encryption $encryption,
    ) {
    }

    public function get(): ?ModelDraft
    {
        $data = $this->requests->getSession()->get(self::SESSION_KEY);

        return \is_array($data) ? ModelDraft::fromArray($data) : null;
    }

    public function put(ModelDraft $draft): void
    {
        $this->requests->getSession()->set(self::SESSION_KEY, $draft->toArray());
    }

    public function clear(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * @throws EncryptionException without a usable APP_ENCRYPTION_KEY
     */
    public function seal(#[\SensitiveParameter] string $apiKey): string
    {
        return $this->encryption->encrypt($apiKey);
    }

    /**
     * @throws EncryptionException when the sealed key cannot be opened
     */
    public function open(#[\SensitiveParameter] ?string $sealedKey): ?string
    {
        return null === $sealedKey ? null : $this->encryption->decrypt($sealedKey);
    }
}
