<?php

namespace App\Entity;

use App\Ai\Role;
use App\Repository\RoleModelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The model one role calls, and the account it calls it on. A role without a row uses the model of
 * its fallback (Role::fallback()).
 */
#[ORM\Entity(repositoryClass: RoleModelRepository::class)]
class RoleModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 20, unique: true, enumType: Role::class)]
        private Role $role,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ProviderAccount $account,
        #[ORM\Column(length: 200)]
        private string $model,
        /** Only offered, and only read, for the providers where the game cannot decide (Provider::hasReasoningSwitch()). */
        #[ORM\Column(options: ['default' => false])]
        private bool $reasoningOff = false,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getAccount(): ProviderAccount
    {
        return $this->account;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isReasoningOff(): bool
    {
        return $this->reasoningOff;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @internal for ModelSettings
     */
    public function change(ProviderAccount $account, string $model, bool $reasoningOff): void
    {
        $this->account = $account;
        $this->model = $model;
        $this->reasoningOff = $reasoningOff;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
