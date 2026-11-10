<?php

namespace App\Command;

use App\Ai\ModelCheck;
use App\Ai\Models;
use App\Ai\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ai:check', description: 'Tries the model of each role (AI_<ROLE>_* in .env) on a tiny task')]
final readonly class CheckModelsCommand
{
    public function __construct(
        private Models $models,
        private ModelCheck $check,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failed = false;
        foreach (Role::cases() as $role) {
            try {
                $problem = $this->check->check($role);
            } catch (\Throwable $exception) {
                $problem = 'The call failed: '.$exception->getMessage();
            }

            $line = \sprintf('%s (%s)', $role->value, $this->models->describe($role));
            if (null === $problem) {
                $io->writeln("<info>OK</info>   {$line}");
                continue;
            }
            $failed = true;
            $io->writeln("<error>FAIL</error> {$line}: {$problem}");
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
