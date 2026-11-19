<?php

namespace App\Entity;

use App\Ai\Provider;
use App\Repository\AiSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The model of the game, chosen in the game rather than in .env.local: one for the whole
 * installation. The API key is kept sealed (Encryption) and is never read from here but by the
 * code that calls the provider.
 */
#[ORM\Entity(repositoryClass: AiSettingRepository::class)]
class AiSetting
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
        #[ORM\Column(nullable: true)]
        private ?string $address,
        #[ORM\Column(length: 200)]
        private string $drawerModel,
        #[ORM\Column(length: 200)]
        private string $guesserModel,
        #[ORM\Column(options: ['default' => false])]
        private bool $reasoningOff,
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

    public function getDrawerModel(): string
    {
        return $this->drawerModel;
    }

    public function getGuesserModel(): string
    {
        return $this->guesserModel;
    }

    public function isReasoningOff(): bool
    {
        return $this->reasoningOff;
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
    public function change(Provider $provider, ?string $address, string $drawerModel, string $guesserModel, bool $reasoningOff): void
    {
        $this->provider = $provider;
        $this->address = $address;
        $this->drawerModel = $drawerModel;
        $this->guesserModel = $guesserModel;
        $this->reasoningOff = $reasoningOff;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @internal for ModelSettings
     */
    public function seal(?string $sealedApiKey, ?string $maskedApiKey): void
    {
        $this->sealedApiKey = $sealedApiKey;
        $this->maskedApiKey = $maskedApiKey;
    }
}
