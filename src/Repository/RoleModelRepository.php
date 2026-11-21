<?php

namespace App\Repository;

use App\Ai\Role;
use App\Entity\ProviderAccount;
use App\Entity\RoleModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoleModel>
 */
class RoleModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleModel::class);
    }

    public function ofRole(Role $role): ?RoleModel
    {
        return $this->findOneBy(['role' => $role]);
    }

    /**
     * @return list<RoleModel> The roles with a model of their own
     */
    public function all(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    /**
     * @return list<Role> The roles that call the account
     */
    public function usingAccount(ProviderAccount $account): array
    {
        // In the order of the roles, which names them together: « Sert à dessiner et à juger ».
        $roles = array_map(static fn (RoleModel $row): Role => $row->getRole(), $this->findBy(['account' => $account]));

        return array_values(array_filter(Role::cases(), static fn (Role $role): bool => \in_array($role, $roles, true)));
    }
}
