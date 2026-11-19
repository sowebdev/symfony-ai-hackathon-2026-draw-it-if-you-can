<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ollama and "own server" are one provider: another OpenAI-compatible service, at an address.';
    }

    public function up(Schema $schema): void
    {
        // Ollama had its address by default; the merged case has none, so it is written down.
        $this->addSql("UPDATE ai_setting SET address = 'http://host.docker.internal:11434' WHERE provider = 'ollama' AND (address IS NULL OR address = '')");
        $this->addSql("UPDATE ai_setting SET provider = 'openai_compatible' WHERE provider IN ('ollama', 'own_server')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE ai_setting SET provider = 'own_server' WHERE provider = 'openai_compatible'");
    }
}
