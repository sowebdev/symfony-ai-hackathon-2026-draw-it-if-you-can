<?php

namespace App\Ai;

use App\Entity\ProviderAccount;
use App\Entity\RoleModel;
use App\Repository\ProviderAccountRepository;
use App\Repository\RoleModelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The model of each role: the accounts and the role models saved from the models screen, the only
 * source. A role without a row of its own uses the model of its fallback (Role::fallback()); with no
 * drawer stored, the game has no model.
 */
final readonly class ModelSettings
{
    public function __construct(
        private ProviderAccountRepository $accounts,
        private RoleModelRepository $roles,
        private EntityManagerInterface $entityManager,
        private Encryption $encryption,
    ) {
    }

    /**
     * What a role calls, from the stored setting: its own model, else the one of its fallback.
     *
     * @internal for Models: the setup holds the API key
     *
     * @throws NoModelException    when no drawer is stored, or the role has no model and no fallback
     * @throws EncryptionException when the stored key cannot be opened
     */
    public function forRole(Role $role): RoleSetup
    {
        if (!$this->isStored()) {
            throw new NoModelException();
        }
        for ($current = $role; null !== $current; $current = $current->fallback()) {
            if (null !== $row = $this->roles->ofRole($current)) {
                $account = $row->getAccount();

                return new RoleSetup($account->getProvider(), $account->getAddress(), $this->openKey($account), $row->getModel(), $row->isReasoningOff());
            }
        }

        throw new NoModelException();
    }

    /**
     * The role whose model this role uses, when it has none of its own: its fallback, or the fallback of that.
     * Null when the role has a model of its own, or none at all.
     */
    public function follows(Role $role): ?Role
    {
        if (null !== $this->roles->ofRole($role)) {
            return null;
        }
        for ($current = $role->fallback(); null !== $current; $current = $current->fallback()) {
            if (null !== $this->roles->ofRole($current)) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Whether a model is chosen: false means the game has none yet (no drawer).
     */
    public function isStored(): bool
    {
        return null !== $this->roles->ofRole(Role::Drawer);
    }

    /**
     * The roles that have a model of their own, for the screen, without any key.
     *
     * @return array<string, RoleView> By role value
     */
    public function views(): array
    {
        $views = [];
        foreach ($this->roles->all() as $row) {
            $account = $row->getAccount();
            $views[$row->getRole()->value] = new RoleView($row->getRole(), $account->getProvider(), $account->getAddress(), $row->getModel(), $row->isReasoningOff());
        }

        return $views;
    }

    /**
     * Every stored account, with the roles that call it.
     *
     * @return list<AccountView>
     */
    public function accounts(): array
    {
        return array_map(fn (ProviderAccount $account): AccountView => $this->view($account), $this->accounts->all());
    }

    /**
     * The stored account of a place, if there is one.
     */
    public function account(Provider $provider, ?string $address): ?AccountView
    {
        $account = $this->accounts->atPlace($provider, $address);

        return null === $account ? null : $this->view($account);
    }

    /**
     * Whether a key is stored for the provider, at any place: what the provider list tags.
     */
    public function hasKeyFor(Provider $provider): bool
    {
        foreach ($this->accounts->findBy(['provider' => $provider]) as $account) {
            if ($account->hasApiKey()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves the models of the roles, all or none: a role set to null has no model of its own any more
     * and follows its fallback (only a role that has one can).
     *
     * @param array<string, ?RoleSetup> $setups By role value. The key of a setup is the key to store for its
     *                                          place; null keeps the one already stored there
     *
     * @throws EncryptionException       when there is a key to store and APP_ENCRYPTION_KEY is missing or wrong
     * @throws \InvalidArgumentException when a role is not complete
     */
    public function save(array $setups): void
    {
        // Checked and sealed first: nothing is touched when a role is not complete or a key cannot be sealed.
        $prepared = [];
        foreach ($setups as $value => $setup) {
            $role = Role::from((string) $value);
            if (null === $setup) {
                if (null === $role->fallback()) {
                    throw new \InvalidArgumentException(\sprintf('The %s has no other role to follow.', $role->value));
                }
                $prepared[$value] = null;
                continue;
            }
            $prepared[$value] = $this->prepare($setup);
        }

        $this->entityManager->wrapInTransaction(function () use ($prepared): void {
            foreach ($prepared as $value => $choice) {
                $role = Role::from((string) $value);
                $row = $this->roles->ofRole($role);
                if (null !== $choice) {
                    $this->saveRole($role, $row, ...$choice);
                } elseif (null !== $row) {
                    $this->entityManager->remove($row);
                }
            }
        });
    }

    /**
     * Saves the model of one role.
     *
     * @param string|null $apiKey null keeps the key already stored for the place
     *
     * @throws EncryptionException       when there is a key to store and APP_ENCRYPTION_KEY is missing or wrong
     * @throws \InvalidArgumentException when the role is not complete
     */
    public function choose(Role $role, Provider $provider, ?string $address, string $model, bool $reasoningOff, #[\SensitiveParameter] ?string $apiKey = null): void
    {
        $this->save([$role->value => new RoleSetup($provider, $address, $apiKey, $model, $reasoningOff)]);
    }

    /**
     * The API key already saved for a place, to keep it when the player does not type one. Only for the
     * same provider and, where the address is a choice, the same address: a key is for one place, and a
     * page that changed the address must not send the saved key somewhere else.
     *
     * @internal for the model screen: it holds the key in memory, never in a page
     *
     * @throws EncryptionException when the stored key cannot be opened
     */
    public function savedKeyFor(Provider $provider, ?string $address): ?string
    {
        $account = $this->accounts->atPlace($provider, $address);

        return null === $account || !$account->hasApiKey() ? null : $this->openKey($account);
    }

    /**
     * Forgets a stored account, with its key. An account a role calls cannot be forgotten.
     *
     * @return bool false when the account is used by a role, or is not there
     */
    public function forget(int $id): bool
    {
        $account = $this->accounts->find($id);
        if (null === $account || [] !== $this->roles->usingAccount($account)) {
            return false;
        }
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return true;
    }

    /**
     * "…3f9a": the end of a key long enough to give nothing away, else "…".
     */
    public static function mask(#[\SensitiveParameter] string $apiKey): string
    {
        return mb_strlen($apiKey) >= 20 ? '…'.mb_substr($apiKey, -4) : '…';
    }

    /**
     * @return array{provider: Provider, address: ?string, model: string, reasoningOff: bool, sealed: array{string, string}|null}
     */
    private function prepare(RoleSetup $setup): array
    {
        $provider = $setup->provider;
        $address = null === $setup->address || '' === trim($setup->address) || !$provider->hasAddress() ? null : trim($setup->address);
        $model = trim($setup->model);
        if ('' === $model) {
            throw new \InvalidArgumentException('Choose a model for each role.');
        }
        if (null === $address && $provider->hasAddress()) {
            throw new \InvalidArgumentException(\sprintf('%s needs the address of the server.', $provider->label()));
        }
        $key = $setup->apiKey();
        if ($provider->needsKey() && null === $key && !($this->accounts->atPlace($provider, $address)?->hasApiKey() ?? false)) {
            throw new \InvalidArgumentException(\sprintf('%s needs an API key.', $provider->label()));
        }

        return [
            'provider' => $provider,
            'address' => $address,
            // Only the player's own switch is kept: for the others the game decides, from the catalog.
            'reasoningOff' => $setup->reasoningOff && $provider->hasReasoningSwitch(),
            'model' => $model,
            'sealed' => null === $key ? null : [$this->encryption->encrypt($key), self::mask($key)],
        ];
    }

    /**
     * @param array{string, string}|null $sealed The key to store, sealed, and its mask
     */
    private function saveRole(Role $role, ?RoleModel $row, Provider $provider, ?string $address, string $model, bool $reasoningOff, ?array $sealed): void
    {
        $account = $this->accounts->atPlace($provider, $address);
        if (null === $account) {
            $account = new ProviderAccount($provider, $address);
            $this->entityManager->persist($account);
        }
        if (null !== $sealed) {
            $account->seal($sealed[0], $sealed[1]);
        }
        if (null === $row) {
            $this->entityManager->persist(new RoleModel($role, $account, $model, $reasoningOff));
        } else {
            $row->change($account, $model, $reasoningOff);
        }
        // The next role of the same save looks for its account: it must find this one.
        $this->entityManager->flush();
    }

    private function view(ProviderAccount $account): AccountView
    {
        return new AccountView((int) $account->getId(), $account->getProvider(), $account->getAddress(), $account->hasApiKey(), $account->getMaskedApiKey(), $this->roles->usingAccount($account));
    }

    private function openKey(ProviderAccount $account): ?string
    {
        $sealed = $account->getSealedApiKey();

        return null === $sealed ? null : $this->encryption->decrypt($sealed);
    }
}
