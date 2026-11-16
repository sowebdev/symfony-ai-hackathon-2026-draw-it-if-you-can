<?php

namespace App\Command;

use App\Setup\EnvLocalFile;
use App\Setup\ModelPreset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks how the AI will play and writes the answer into .env.local. Run by `make install`;
 * the options skip the questions (a script, the CI).
 */
#[AsCommand(name: 'app:setup', description: 'Chooses the models of the game and writes them into .env.local')]
final readonly class SetupCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/.env.local')]
        private string $envLocalPath,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'anthropic, mistral or server')] ?string $provider = null,
        #[Option(description: 'The API key, if the provider needs one')] ?string $apiKey = null,
        #[Option(description: 'The address of your model server')] ?string $url = null,
        #[Option(description: 'The model that draws')] ?string $drawerModel = null,
        #[Option(description: 'The model that guesses')] ?string $guesserModel = null,
    ): int {
        $existing = is_file($this->envLocalPath) ? (string) file_get_contents($this->envLocalPath) : '';
        $configured = str_contains($existing, 'AI_DRAWER_');

        if (null === $provider) {
            if (!$input->isInteractive()) {
                $io->writeln($configured
                    ? 'Models kept as set in .env.local.'
                    : 'No models chosen: run `make setup` in a terminal, or pass --provider.');

                return Command::SUCCESS;
            }
            if ($configured && !$io->confirm('Your .env.local already sets the models. Change them?', false)) {
                return Command::SUCCESS;
            }
            $labels = array_map(static fn (ModelPreset $preset): string => $preset->label(), ModelPreset::cases());
            $answer = $io->choice('How will the AI play?', $labels, $labels[0]);
            $preset = ModelPreset::cases()[array_search($answer, $labels, true)];
        } else {
            $preset = ModelPreset::tryFrom($provider);
            if (null === $preset) {
                $io->error('Unknown provider: choose anthropic, mistral or server.');

                return Command::INVALID;
            }
        }

        $interactive = null === $provider;
        $baseUrl = $url ?? $preset->baseUrl();
        $drawer = $drawerModel ?? $preset->drawerModel();
        $guesser = $guesserModel ?? $preset->guesserModel();
        $key = $apiKey ?? '';

        if ($interactive && ModelPreset::Server === $preset) {
            $io->note('From inside Docker, your machine is host.docker.internal. On Linux, start Ollama with OLLAMA_HOST=0.0.0.0 so that the game can reach it.');
            $baseUrl = $io->ask('Address of the server', $baseUrl);
            $drawer = $io->ask('Model that draws (it must see images and call tools)', $drawer);
            $guesser = $io->ask('Model that guesses (it must see images)', $guesser);
            $key = (string) $io->ask('API key, if your server asks for one', '', static fn (?string $value): string => (string) $value);
        }
        if ($interactive && $preset->needsApiKey()) {
            $key = (string) $io->askHidden('Your API key', static function (?string $value): string {
                if (null === $value || '' === trim($value)) {
                    throw new \RuntimeException('The key is needed to play.');
                }

                return trim($value);
            });
        }
        if ($preset->needsApiKey() && '' === $key) {
            $io->error('This provider needs an API key: pass --api-key.');

            return Command::INVALID;
        }

        file_put_contents($this->envLocalPath, EnvLocalFile::merge($existing, $preset->values($baseUrl, $drawer, $guesser, $key)));
        $io->success(\sprintf('Saved in .env.local: %s draws, %s guesses. Change them there at any time.', $drawer, $guesser));

        return Command::SUCCESS;
    }
}
