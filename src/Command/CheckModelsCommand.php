<?php

namespace App\Command;

use App\Ai\ModelHealth;
use App\Ai\ModelSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(name: 'app:ai:check', description: 'Tries the model of each role chosen on the models screen, on a tiny task')]
final readonly class CheckModelsCommand
{
    public function __construct(private ModelHealth $health, private ModelSettings $settings, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->settings->isStored()) {
            $io->writeln('<error>No model is chosen yet.</error> Choose one on the models screen: '.$this->urls->generate('models', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL));

            return Command::FAILURE;
        }
        $failed = false;
        // One line per role, saying where it answers; a role that follows another says so, and has its result.
        foreach ($this->health->report(fresh: true, roleBudgetS: null) as ['role' => $role, 'model' => $model, 'problem' => $problem, 'follows' => $follows]) {
            $name = null === $follows ? $role : "{$role} follows {$follows}";
            if (null === $problem) {
                $io->writeln("<info>OK</info>   {$name} ({$model})");
                continue;
            }
            $failed = true;
            $io->writeln("<error>FAIL</error> {$name} ({$model}): {$problem}");
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
