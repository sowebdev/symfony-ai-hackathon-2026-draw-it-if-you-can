<?php

namespace App\Ai;

use App\Entity\AiSetting;
use App\Repository\AiSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The model of each role: the one setting saved from the models screen, the only source. With none,
 * the game has no model.
 */
final readonly class ModelSettings
{
    public function __construct(
        private AiSettingRepository $repository,
        private EntityManagerInterface $entityManager,
        private Encryption $encryption,
    ) {
    }

    /**
     * What a role calls, from the stored setting.
     *
     * @internal for Models: the setup holds the API key
     *
     * @throws NoModelException    when no setting is stored
     * @throws EncryptionException when the stored key cannot be opened
     */
    public function forRole(Role $role): RoleSetup
    {
        $setting = $this->repository->current() ?? throw new NoModelException();

        return new RoleSetup(
            $setting->getProvider(),
            $setting->getAddress(),
            $this->openKey($setting),
            Role::Drawer === $role ? $setting->getDrawerModel() : $setting->getGuesserModel(),
            $setting->isReasoningOff(),
        );
    }

    /**
     * Whether a model is chosen: false means the game has none yet.
     */
    public function isStored(): bool
    {
        return null !== $this->repository->current();
    }

    /**
     * The setting for the screen, without its key; null when no model is chosen yet.
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
        if (null === $address && $provider->hasAddress()) {
            throw new \InvalidArgumentException(\sprintf('%s needs the address of the server.', $provider->label()));
        }

        // Only the player's own switch is kept: for the others the game decides, from the catalog.
        $reasoningOff = $reasoningOff && $provider->hasReasoningSwitch();

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
     * The API key already saved, to keep it when the player leaves the field empty. Only for the same
     * provider and, where the address is a choice, the same address: a key is for one place, and a
     * page that changed the address must not send the saved key somewhere else.
     *
     * @internal for the model screen: it holds the key in memory, never in a page
     *
     * @throws EncryptionException when the stored key cannot be opened
     */
    public function savedKeyFor(Provider $provider, ?string $address): ?string
    {
        $setting = $this->repository->current();
        if (null === $setting || $setting->getProvider() !== $provider || !$setting->hasApiKey()) {
            return null;
        }
        if ($provider->addressOr($setting->getAddress()) !== $provider->addressOr($address)) {
            return null;
        }

        return $this->openKey($setting);
    }

    /**
     * Forgets the setting: the game has no model again.
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
