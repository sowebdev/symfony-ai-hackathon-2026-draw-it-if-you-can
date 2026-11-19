<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The model of the game, chosen in the game: provider, address, model of each role, reasoning, and the API key sealed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_setting (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              sealed_api_key CLOB DEFAULT NULL,
              masked_api_key VARCHAR(10) DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              provider VARCHAR(20) NOT NULL,
              address VARCHAR(255) DEFAULT NULL,
              drawer_model VARCHAR(200) NOT NULL,
              guesser_model VARCHAR(200) NOT NULL,
              reasoning_off BOOLEAN DEFAULT 0 NOT NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_setting');
    }
}
