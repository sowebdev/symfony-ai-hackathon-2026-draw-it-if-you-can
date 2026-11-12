<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112081755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Games of several rounds against the clock: number of rounds and who starts, number and length of each round';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game ADD COLUMN round_count INTEGER DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN first_drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__round AS
            SELECT
              id,
              started_at_ms,
              found_after_ms,
              target_word,
              game_id,
              ai_drawing_started,
              ai_drawing_finished,
              given_up,
              drawn_by
            FROM
              round
        SQL);
        $this->addSql('DROP TABLE round');
        $this->addSql(<<<'SQL'
            CREATE TABLE round (
              id BLOB NOT NULL,
              started_at_ms BIGINT NOT NULL,
              found_after_ms INTEGER DEFAULT NULL,
              target_word VARCHAR(100) NOT NULL,
              game_id BLOB NOT NULL,
              ai_drawing_started BOOLEAN DEFAULT 0 NOT NULL,
              ai_drawing_finished BOOLEAN DEFAULT 0 NOT NULL,
              given_up BOOLEAN DEFAULT 0 NOT NULL,
              drawn_by VARCHAR(10) DEFAULT 'player' NOT NULL,
              number INTEGER DEFAULT 1 NOT NULL,
              duration_ms INTEGER DEFAULT 60000 NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON
              UPDATE
                NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO round (
              id, started_at_ms, found_after_ms,
              target_word, game_id, ai_drawing_started,
              ai_drawing_finished, given_up, drawn_by
            )
            SELECT
              id,
              started_at_ms,
              found_after_ms,
              target_word,
              game_id,
              ai_drawing_started,
              ai_drawing_finished,
              given_up,
              drawn_by
            FROM
              __temp__round
        SQL);
        $this->addSql('DROP TABLE __temp__round');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5EEEA34E48FD90596901F54 ON round (game_id, number)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__game AS SELECT id, created_at, theme, language FROM game');
        $this->addSql('DROP TABLE game');
        $this->addSql(<<<'SQL'
            CREATE TABLE game (
              id BLOB NOT NULL,
              created_at DATETIME NOT NULL,
              theme VARCHAR(255) NOT NULL,
              language VARCHAR(255) NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO game (id, created_at, theme, language)
            SELECT
              id,
              created_at,
              theme,
              language
            FROM
              __temp__game
        SQL);
        $this->addSql('DROP TABLE __temp__game');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__round AS
            SELECT
              id,
              started_at_ms,
              found_after_ms,
              ai_drawing_started,
              ai_drawing_finished,
              given_up,
              drawn_by,
              target_word,
              game_id
            FROM
              round
        SQL);
        $this->addSql('DROP TABLE round');
        $this->addSql(<<<'SQL'
            CREATE TABLE round (
              id BLOB NOT NULL,
              started_at_ms BIGINT NOT NULL,
              found_after_ms INTEGER DEFAULT NULL,
              ai_drawing_started BOOLEAN DEFAULT 0 NOT NULL,
              ai_drawing_finished BOOLEAN DEFAULT 0 NOT NULL,
              given_up BOOLEAN DEFAULT 0 NOT NULL,
              drawn_by VARCHAR(10) DEFAULT 'player' NOT NULL,
              target_word VARCHAR(100) NOT NULL,
              game_id BLOB NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO round (
              id, started_at_ms, found_after_ms,
              ai_drawing_started, ai_drawing_finished,
              given_up, drawn_by, target_word,
              game_id
            )
            SELECT
              id,
              started_at_ms,
              found_after_ms,
              ai_drawing_started,
              ai_drawing_finished,
              given_up,
              drawn_by,
              target_word,
              game_id
            FROM
              __temp__round
        SQL);
        $this->addSql('DROP TABLE __temp__round');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
    }
}
