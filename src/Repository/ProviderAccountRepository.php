<?php

namespace App\Repository;

use App\Ai\Provider;
use App\Entity\ProviderAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderAccount>
 */
class ProviderAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderAccount::class);
    }

    /**
     * The account of a place: the same provider and, where the address is a choice, the same address.
     */
    public function atPlace(Provider $provider, ?string $address): ?ProviderAccount
    {
        $place = $provider->addressOr($address);
        foreach ($this->findBy(['provider' => $provider]) as $account) {
            if ($provider->addressOr($account->getAddress()) === $place) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return list<ProviderAccount> In the order they were stored
     */
    public function all(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }
}
