<?php

namespace App\Entity;

use App\Ai\Provider;
use App\Repository\ProviderAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A place the game can call: a provider and, for a server of one's own, its address, with the API key
 * for it. The key is kept sealed (Encryption) and is never read from here but by the code that calls
 * the provider. An account stays until the player forgets it, even when no role uses it any more.
 */
#[ORM\Entity(repositoryClass: ProviderAccountRepository::class)]
#[ORM\UniqueConstraint(name: 'provider_account_place', columns: ['provider', 'address'])]
class ProviderAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The API key, sealed: base64 of the nonce and the encrypted text. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sealedApiKey = null;

    /** The end of the key as the screen shows it ("…3f9a"), kept so that showing it needs no decryption. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $maskedApiKey = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 20, enumType: Provider::class)]
        private Provider $provider,
        /** Null when the provider has a fixed address. */
        #[ORM\Column(nullable: true)]
        private ?string $address = null,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function hasApiKey(): bool
    {
        return null !== $this->sealedApiKey;
    }

    /**
     * The key as the screen may show it: "…3f9a", or "…" for a key too short to give away its end.
     */
    public function getMaskedApiKey(): ?string
    {
        return $this->maskedApiKey;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @internal for ModelSettings: the sealed key, to be opened by Encryption
     */
    public function getSealedApiKey(): ?string
    {
        return $this->sealedApiKey;
    }

    /**
     * @internal for ModelSettings
     */
    public function seal(?string $sealedApiKey, ?string $maskedApiKey): void
    {
        $this->sealedApiKey = $sealedApiKey;
        $this->maskedApiKey = $maskedApiKey;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
