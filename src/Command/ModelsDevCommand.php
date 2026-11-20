<?php

namespace App\Command;

use App\Ai\ModelsDev;
use App\Ai\Provider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads the catalog of models.dev for the game's providers into var/models-dev.json, read instead
 * of the extract versioned with the game, and says, per provider, its current range and the model the
 * models screen will preselect. Run by `make install`; run it again any time.
 */
#[AsCommand(name: 'app:models-dev', description: 'Downloads the catalog of models.dev and shows the model recommended for each provider')]
final readonly class ModelsDevCommand
{
    public function __construct(private ModelsDev $modelsDev)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $count = $this->modelsDev->update();
        } catch (\Throwable $e) {
            $io->warning('models.dev did not answer ('.$e->getMessage().'): the game keeps the extract of models.dev it has, '.ModelsDev::path().'.');

            return Command::SUCCESS;
        }
        $io->writeln(\sprintf('models.dev: %d models of the game\'s providers, in %s.', $count, ModelsDev::updatedPath()));
        foreach ([Provider::Anthropic, Provider::OpenAi, Provider::Mistral] as $provider) {
            // Every model of the file, as if the provider listed them all.
            $all = array_map(strval(...), array_keys(ModelsDev::models($provider)));
            $models = ModelsDev::models($provider);
            $io->writeln(\sprintf(
                '  %-10s %s → <info>%s</info>',
                $provider->value,
                implode(' < ', array_map(static fn (string $id): string => \sprintf('%s ($%s)', $id, $models[$id]['cost']['output'] ?? '?'), ModelsDev::range($provider, $all))),
                ModelsDev::recommended($provider, $all) ?? 'nothing',
            ));
        }

        return Command::SUCCESS;
    }
}
