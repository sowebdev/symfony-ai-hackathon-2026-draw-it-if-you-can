<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The settings of the game (one row), and what a game keeps of them: its themes, who draws, the length of its rounds.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, themes CLOB NOT NULL, turns VARCHAR(10) NOT NULL, rounds INTEGER NOT NULL, round_seconds INTEGER NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('ALTER TABLE game ADD COLUMN themes CLOB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN turns VARCHAR(10) DEFAULT \'alternate\' NOT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN round_seconds INTEGER DEFAULT 60 NOT NULL');
        // The games before the settings were played on every theme.
        $this->addSql('UPDATE game SET themes = \'["animals","everyday-objects","developer","places","characters","nature","transport"]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_setting');
        $this->addSql('CREATE TEMPORARY TABLE __temp__game AS SELECT id, created_at, copilot_name, agent_nickname, copilot_seen_at, left_at, opened_at, next_theme, language, round_count, first_drawn_by, mode FROM game');
        $this->addSql('DROP TABLE game');
        $this->addSql('CREATE TABLE game (id BLOB NOT NULL, created_at DATETIME NOT NULL, copilot_name VARCHAR(100) DEFAULT NULL, agent_nickname VARCHAR(30) DEFAULT NULL, copilot_seen_at DATETIME DEFAULT NULL, left_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, next_theme VARCHAR(255) DEFAULT NULL, language VARCHAR(255) NOT NULL, round_count INTEGER DEFAULT 1 NOT NULL, first_drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL, mode VARCHAR(10) DEFAULT \'solo\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO game (id, created_at, copilot_name, agent_nickname, copilot_seen_at, left_at, opened_at, next_theme, language, round_count, first_drawn_by, mode) SELECT id, created_at, copilot_name, agent_nickname, copilot_seen_at, left_at, opened_at, next_theme, language, round_count, first_drawn_by, mode FROM __temp__game');
        $this->addSql('DROP TABLE __temp__game');
    }
}
