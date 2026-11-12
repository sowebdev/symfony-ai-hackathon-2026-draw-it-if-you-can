<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112094502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Games with a copilot: the mode, and the name and last call of the agent';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game ADD COLUMN copilot_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN copilot_seen_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN with_copilot BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__game AS SELECT id, created_at, theme, language, round_count, first_drawn_by FROM game');
        $this->addSql('DROP TABLE game');
        $this->addSql('CREATE TABLE game (id BLOB NOT NULL, created_at DATETIME NOT NULL, theme VARCHAR(255) NOT NULL, language VARCHAR(255) NOT NULL, round_count INTEGER DEFAULT 1 NOT NULL, first_drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO game (id, created_at, theme, language, round_count, first_drawn_by) SELECT id, created_at, theme, language, round_count, first_drawn_by FROM __temp__game');
        $this->addSql('DROP TABLE __temp__game');
    }
}
