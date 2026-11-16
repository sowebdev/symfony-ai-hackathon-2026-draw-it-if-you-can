<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116095046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The initial schema: games, rounds, strokes, guesses, hints and tips (15 migrations of the first days, merged on 16/11/2026).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE game (
              id BLOB NOT NULL,
              created_at DATETIME NOT NULL,
              copilot_name VARCHAR(100) DEFAULT NULL,
              agent_nickname VARCHAR(30) DEFAULT NULL,
              copilot_seen_at DATETIME DEFAULT NULL,
              left_at DATETIME DEFAULT NULL,
              next_theme VARCHAR(255) DEFAULT NULL,
              language VARCHAR(255) NOT NULL,
              round_count INTEGER DEFAULT 1 NOT NULL,
              first_drawn_by VARCHAR(10) DEFAULT 'player' NOT NULL,
              mode VARCHAR(10) DEFAULT 'solo' NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE guess (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              accepted BOOLEAN NOT NULL,
              word VARCHAR(100) NOT NULL,
              said_after_ms INTEGER NOT NULL,
              author VARCHAR(10) DEFAULT 'player' NOT NULL,
              round_id BLOB NOT NULL,
              CONSTRAINT FK_32D30F96A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_32D30F96A6005CA0 ON guess (round_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE hint (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              word VARCHAR(100) NOT NULL,
              said_after_ms INTEGER NOT NULL,
              round_id BLOB NOT NULL,
              CONSTRAINT FK_34C60353A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_34C60353A6005CA0 ON hint (round_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE round (
              id BLOB NOT NULL,
              started_at_ms BIGINT NOT NULL,
              found_after_ms INTEGER DEFAULT NULL,
              found_by VARCHAR(10) DEFAULT NULL,
              ai_drawing_started BOOLEAN DEFAULT 0 NOT NULL,
              ai_drawing_finished BOOLEAN DEFAULT 0 NOT NULL,
              given_up BOOLEAN DEFAULT 0 NOT NULL,
              drawn_by VARCHAR(10) DEFAULT 'player' NOT NULL,
              strokes_seen_by_agent INTEGER DEFAULT 0 NOT NULL,
              strokes_told_to_agent INTEGER DEFAULT 0 NOT NULL,
              ai_guesses_told_to_agent INTEGER DEFAULT 0 NOT NULL,
              number INTEGER DEFAULT 1 NOT NULL,
              target_word VARCHAR(100) NOT NULL,
              duration_ms INTEGER DEFAULT 60000 NOT NULL,
              theme VARCHAR(255) NOT NULL,
              game_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5EEEA34E48FD90596901F54 ON round (game_id, number)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE stroke (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              points CLOB NOT NULL,
              part VARCHAR(100) DEFAULT NULL,
              written CLOB DEFAULT NULL,
              position INTEGER NOT NULL,
              round_id BLOB NOT NULL,
              CONSTRAINT FK_94A5B72A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_94A5B72A6005CA0462CE4F5 ON stroke (round_id, position)');
        $this->addSql('CREATE INDEX IDX_94A5B72A6005CA0 ON stroke (round_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE tip (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              text VARCHAR(120) NOT NULL,
              said_after_ms INTEGER NOT NULL,
              round_id BLOB NOT NULL,
              CONSTRAINT FK_4883B84CA6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_4883B84CA6005CA0 ON tip (round_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE guess');
        $this->addSql('DROP TABLE hint');
        $this->addSql('DROP TABLE round');
        $this->addSql('DROP TABLE stroke');
        $this->addSql('DROP TABLE tip');
    }
}
