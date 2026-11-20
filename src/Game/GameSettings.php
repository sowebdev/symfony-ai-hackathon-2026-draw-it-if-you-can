<?php

namespace App\Game;

use App\Entity\GameSetting;
use App\Repository\GameSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The settings of the next games: the saved row, or the defaults while there is none.
 */
final readonly class GameSettings
{
    public function __construct(
        private GameSettingRepository $repository,
        private EntityManagerInterface $entityManager,
        #[Autowire('%game.rounds%')]
        private int $defaultRounds,
        #[Autowire('%game.round_seconds%')]
        private int $defaultRoundSeconds,
    ) {
    }

    public function get(): Settings
    {
        return $this->repository->row()?->settings() ?? $this->defaults();
    }

    public function defaults(): Settings
    {
        return Settings::defaults($this->defaultRounds, $this->defaultRoundSeconds);
    }

    public function save(Settings $settings): void
    {
        $row = $this->repository->row();
        if (null === $row) {
            $this->entityManager->persist(new GameSetting($settings));
        } else {
            $row->apply($settings);
        }
        $this->entityManager->flush();
    }
}
