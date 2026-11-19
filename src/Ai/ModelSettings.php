<?php

namespace App\Ai;

use App\Entity\AiSetting;
use App\Repository\AiSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The model of each role: the setting saved in the game, if there is one, else the AI_* variables
 * of .env and .env.local, as before the setting existed (make setup, app:setup and the CI).
 */
final readonly class ModelSettings
{
    public function __construct(
        private AiSettingRepository $repository,
        private EntityManagerInterface $entityManager,
        private Encryption $encryption,
        #[Autowire('%env(AI_DRAWER_BASE_URL)%')]
        private string $drawerBaseUrl,
        #[Autowire('%env(AI_DRAWER_MODEL)%')]
        private string $drawerModel,
        #[\SensitiveParameter]
        #[Autowire('%env(AI_DRAWER_API_KEY)%')]
        private string $drawerApiKey,
        #[Autowire('%env(bool:AI_DRAWER_DISABLE_THINKING)%')]
        private bool $drawerDisableThinking,
        #[Autowire('%env(AI_GUESSER_BASE_URL)%')]
        private string $guesserBaseUrl,
        #[Autowire('%env(AI_GUESSER_MODEL)%')]
        private string $guesserModel,
        #[\SensitiveParameter]
        #[Autowire('%env(AI_GUESSER_API_KEY)%')]
        private string $guesserApiKey,
        #[Autowire('%env(bool:AI_GUESSER_DISABLE_THINKING)%')]
        private bool $guesserDisableThinking,
    ) {
    }

    /**
     * What a role calls: the database first, then the environment (a server that speaks the API of
     * OpenAI, at the address of the variable).
     *
     * @internal for Models: the setup holds the API key
     *
     * @throws EncryptionException when the stored key cannot be opened
     */
    public function forRole(Role $role): RoleSetup
    {
        if (null !== $setting = $this->repository->current()) {
            return new RoleSetup(
                $setting->getProvider(),
                $setting->getAddress(),
                $this->openKey($setting),
                Role::Drawer === $role ? $setting->getDrawerModel() : $setting->getGuesserModel(),
                $setting->isReasoningOff(),
            );
        }

        return Role::Drawer === $role
            ? new RoleSetup(Provider::OwnServer, $this->drawerBaseUrl, $this->drawerApiKey, $this->drawerModel, $this->drawerDisableThinking)
            : new RoleSetup(Provider::OwnServer, $this->guesserBaseUrl, $this->guesserApiKey, $this->guesserModel, $this->guesserDisableThinking);
    }

    /**
     * Whether the game was set up in the game: false means it still reads the AI_* variables.
     */
    public function isStored(): bool
    {
        return null !== $this->repository->current();
    }

    /**
     * The setting for the screen, without its key; null when the game still reads the variables.
     */
    public function view(): ?SettingView
    {
        $setting = $this->repository->current();

        return null === $setting ? null : new SettingView(
            $setting->getProvider(),
            $setting->getAddress(),
            $setting->getDrawerModel(),
            $setting->getGuesserModel(),
            $setting->isReasoningOff(),
            $setting->hasApiKey(),
            $setting->getMaskedApiKey(),
        );
    }

    /**
     * Saves the one setting of the installation.
     *
     * @param string|null $apiKey null keeps the key already stored (unless the provider changes: a key is
     *                            for one provider), an empty string removes it
     *
     * @throws EncryptionException       when there is a key to store and APP_ENCRYPTION_KEY is missing or wrong
     * @throws \InvalidArgumentException when the setting is not complete
     */
    public function save(
        Provider $provider,
        ?string $address,
        string $drawerModel,
        string $guesserModel,
        bool $reasoningOff,
        #[\SensitiveParameter] ?string $apiKey = null,
    ): void {
        $address = null === $address || '' === trim($address) || !$provider->hasAddress() ? null : trim($address);
        $drawerModel = trim($drawerModel);
        $guesserModel = trim($guesserModel);
        if ('' === $drawerModel || '' === $guesserModel) {
            throw new \InvalidArgumentException('Choose a model for each role.');
        }
        if (null === $address && $provider->hasAddress() && null === $provider->defaultAddress()) {
            throw new \InvalidArgumentException(\sprintf('%s needs the address of the server.', $provider->label()));
        }

        $setting = $this->repository->current();
        $kept = null === $apiKey && $setting?->getProvider() === $provider ? $setting : null;
        $keepsKey = null !== $kept;
        $newKey = trim((string) $apiKey);
        $willHaveKey = null !== $kept ? $kept->hasApiKey() : '' !== $newKey;
        if ($provider->needsKey() && !$willHaveKey) {
            throw new \InvalidArgumentException(\sprintf('%s needs an API key.', $provider->label()));
        }

        // Sealed first: nothing is touched when the key cannot be.
        $sealed = !$keepsKey && '' !== $newKey ? $this->encryption->encrypt($newKey) : null;

        if (null === $setting) {
            $setting = new AiSetting($provider, $address, $drawerModel, $guesserModel, $reasoningOff);
            $this->entityManager->persist($setting);
        } else {
            $setting->change($provider, $address, $drawerModel, $guesserModel, $reasoningOff);
        }
        if (!$keepsKey) {
            $setting->seal($sealed, null === $sealed ? null : self::mask($newKey));
        }
        $this->entityManager->flush();
    }

    /**
     * Forgets the setting: the game reads the AI_* variables again.
     */
    public function remove(): void
    {
        if (null !== $setting = $this->repository->current()) {
            $this->entityManager->remove($setting);
            $this->entityManager->flush();
        }
    }

    /**
     * "…3f9a": the end of a key long enough to give nothing away, else "…".
     */
    public static function mask(#[\SensitiveParameter] string $apiKey): string
    {
        return mb_strlen($apiKey) >= 20 ? '…'.mb_substr($apiKey, -4) : '…';
    }

    private function openKey(AiSetting $setting): ?string
    {
        $sealed = $setting->getSealedApiKey();

        return null === $sealed ? null : $this->encryption->decrypt($sealed);
    }
}
