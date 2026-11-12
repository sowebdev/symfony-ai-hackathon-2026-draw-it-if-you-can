<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112093051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hints of the copilot, an agent connected over MCP';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hint (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, word VARCHAR(100) NOT NULL, said_after_ms INTEGER NOT NULL, round_id BLOB NOT NULL, CONSTRAINT FK_34C60353A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_34C60353A6005CA0 ON hint (round_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE hint');
    }
}
