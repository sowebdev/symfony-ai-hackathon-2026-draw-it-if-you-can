<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the key that seals the API key of the database (APP_ENCRYPTION_KEY) into .env.local, only
 * when there is none: never replaced, since losing it loses the stored API key. Run by `make install`.
 */
#[AsCommand(name: 'app:encryption-key', description: 'Writes APP_ENCRYPTION_KEY into .env.local when there is none, never replaces one')]
final readonly class EncryptionKeyCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/.env.local')]
        private string $envLocalPath,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $content = is_file($this->envLocalPath) ? (string) file_get_contents($this->envLocalPath) : '';
        if (1 === preg_match('/^APP_ENCRYPTION_KEY=["\']?[^"\'\s]/m', $content)) {
            return Command::SUCCESS;
        }

        $line = 'APP_ENCRYPTION_KEY='.base64_encode(random_bytes(32))."\n";
        $separator = '' === $content || str_ends_with($content, "\n") ? '' : "\n";
        if (false === file_put_contents($this->envLocalPath, $content.$separator.$line)) {
            $io->error('.env.local cannot be written: add APP_ENCRYPTION_KEY=<32 random bytes in base64> to it by hand.');

            return Command::FAILURE;
        }
        $io->writeln('APP_ENCRYPTION_KEY written to .env.local: keep it, the API key of the database cannot be read without it.');

        return Command::SUCCESS;
    }
}
