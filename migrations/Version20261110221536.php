<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110221536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The AI draws: who draws a round, the state of the drawing of the AI, giving up, and what the model wrote for each stroke';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE round ADD COLUMN ai_drawing_started BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE round ADD COLUMN ai_drawing_finished BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE round ADD COLUMN given_up BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE round ADD COLUMN drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL');
        $this->addSql('ALTER TABLE stroke ADD COLUMN part VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE stroke ADD COLUMN written CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__round AS SELECT id, started_at_ms, found_after_ms, target_word, game_id FROM round');
        $this->addSql('DROP TABLE round');
        $this->addSql('CREATE TABLE round (id BLOB NOT NULL, started_at_ms BIGINT NOT NULL, found_after_ms INTEGER DEFAULT NULL, target_word VARCHAR(100) NOT NULL, game_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO round (id, started_at_ms, found_after_ms, target_word, game_id) SELECT id, started_at_ms, found_after_ms, target_word, game_id FROM __temp__round');
        $this->addSql('DROP TABLE __temp__round');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__stroke AS SELECT id, points, position, round_id FROM stroke');
        $this->addSql('DROP TABLE stroke');
        $this->addSql('CREATE TABLE stroke (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, points CLOB NOT NULL, position INTEGER NOT NULL, round_id BLOB NOT NULL, CONSTRAINT FK_94A5B72A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO stroke (id, points, position, round_id) SELECT id, points, position, round_id FROM __temp__stroke');
        $this->addSql('DROP TABLE __temp__stroke');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_94A5B72A6005CA0462CE4F5 ON stroke (round_id, position)');
        $this->addSql('CREATE INDEX IDX_94A5B72A6005CA0 ON stroke (round_id)');
    }
}
