<?php

namespace App\Command;

use App\Setup\EnvLocalFile;
use App\Setup\ModelSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks where the model runs and writes the answer into .env.local, the same model for both roles.
 * Run by `make install`; the options skip the questions (a script, the CI).
 */
#[AsCommand(name: 'app:setup', description: 'Chooses the model of the game and writes it into .env.local')]
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
        #[Option(description: 'cloud or local')] ?string $source = null,
        #[Option(description: 'The address of the API or of your server')] ?string $url = null,
        #[Option(description: 'The model, for both roles')] ?string $model = null,
        #[Option(description: 'The API key, empty if your server needs none')] ?string $apiKey = null,
        #[Option(description: 'on or off (default: off for a local server, on for a cloud API)')] ?string $reasoning = null,
    ): int {
        $existing = is_file($this->envLocalPath) ? (string) file_get_contents($this->envLocalPath) : '';

        if (null === $source) {
            if (!$input->isInteractive()) {
                $io->writeln(str_contains($existing, 'AI_DRAWER_')
                    ? 'Model kept as set in .env.local.'
                    : 'No model chosen: run `make setup` in a terminal, or pass --source.');

                return Command::SUCCESS;
            }

            return $this->ask($io, $existing);
        }

        $chosen = ModelSource::tryFrom($source);
        $url ??= $chosen?->defaultUrl();
        $model ??= $chosen?->defaultModel();
        $problem = match (true) {
            null === $chosen => 'Unknown source: choose cloud or local.',
            null === $url => 'Give the address of the API: --url.',
            null === $model => 'Give the model: --model.',
            $chosen->needsApiKey() && (null === $apiKey || '' === $apiKey) => 'A cloud API needs a key: --api-key.',
            null !== $reasoning && !\in_array($reasoning, ['on', 'off'], true) => 'Reasoning is on or off.',
            default => null,
        };
        if (null !== $problem) {
            $io->error($problem);

            return Command::INVALID;
        }

        return $this->save($io, $existing, $url, $model, $apiKey ?? '', null === $reasoning ? $chosen->reasoningOffByDefault() : 'off' === $reasoning);
    }

    private function ask(SymfonyStyle $io, string $existing): int
    {
        if (str_contains($existing, 'AI_DRAWER_') && !$io->confirm('Your .env.local already sets a model. Change it?', false)) {
            return Command::SUCCESS;
        }

        $labels = array_map(static fn (ModelSource $source): string => $source->label(), ModelSource::cases());
        $source = ModelSource::cases()[array_search($io->choice('Where does the model run?', $labels, $labels[0]), $labels, true)];
        $required = static function (?string $value): string {
            if (null === $value || '' === trim($value)) {
                throw new \RuntimeException('This one is needed.');
            }

            return trim($value);
        };

        if (ModelSource::Cloud === $source) {
            $io->writeln(' e.g. https://api.openai.com, https://api.anthropic.com, https://api.mistral.ai');
            $url = $io->ask('Address of the API', null, $required);
            $io->writeln(' It must see images and call tools, e.g. claude-haiku-5-5.');
            $model = $io->ask('Model', null, $required);
            $key = $io->askHidden('API key', $required);
        } else {
            $io->writeln(' From inside Docker, your machine is host.docker.internal. On Linux, start Ollama with OLLAMA_HOST=0.0.0.0.');
            $url = $io->ask('Address of the server', $source->defaultUrl(), $required);
            $io->writeln(' It must see images and call tools. With Ollama: ollama pull '.ModelSource::LOCAL_MODEL);
            $model = $io->ask('Model', $source->defaultModel(), $required);
            $key = (string) $io->ask('API key, if your server asks for one', '', static fn (?string $value): string => trim((string) $value));
        }

        $io->writeln(' A local model that reasons takes tens of seconds per word; some cloud APIs refuse the option.');
        $reasoningOff = $io->confirm('Turn reasoning off?', $source->reasoningOffByDefault());

        return $this->save($io, $existing, $url, $model, $key, $reasoningOff);
    }

    private function save(SymfonyStyle $io, string $existing, string $url, string $model, string $key, bool $reasoningOff): int
    {
        file_put_contents($this->envLocalPath, EnvLocalFile::merge($existing, ModelSource::values($url, $model, $key, $reasoningOff)));
        $io->success(\sprintf('Saved in .env.local: %s draws and guesses. Change it with make setup, or there by hand.', $model));

        return Command::SUCCESS;
    }
}
