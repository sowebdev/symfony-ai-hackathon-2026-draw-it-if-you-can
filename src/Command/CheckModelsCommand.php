<?php

namespace App\Command;

use App\Ai\ModelHealth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ai:check', description: 'Tries the model of each role (AI_<ROLE>_* in .env) on a tiny task')]
final readonly class CheckModelsCommand
{
    public function __construct(private ModelHealth $health)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failed = false;
        foreach ($this->health->report(fresh: true) as ['role' => $role, 'model' => $model, 'problem' => $problem]) {
            if (null === $problem) {
                $io->writeln("<info>OK</info>   {$role} ({$model})");
                continue;
            }
            $failed = true;
            $io->writeln("<error>FAIL</error> {$role} ({$model}): {$problem}");
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
