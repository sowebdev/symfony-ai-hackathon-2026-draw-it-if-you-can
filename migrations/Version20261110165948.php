<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110165948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game (id BLOB NOT NULL, created_at DATETIME NOT NULL, theme VARCHAR(255) NOT NULL, language VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE guess (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, accepted BOOLEAN NOT NULL, word VARCHAR(100) NOT NULL, said_after_ms INTEGER NOT NULL, round_id BLOB NOT NULL, CONSTRAINT FK_32D30F96A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_32D30F96A6005CA0 ON guess (round_id)');
        $this->addSql('CREATE TABLE round (id BLOB NOT NULL, started_at_ms BIGINT NOT NULL, found_after_ms INTEGER DEFAULT NULL, target_word VARCHAR(100) NOT NULL, game_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql('CREATE TABLE stroke (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, points CLOB NOT NULL, position INTEGER NOT NULL, round_id BLOB NOT NULL, CONSTRAINT FK_94A5B72A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_94A5B72A6005CA0462CE4F5 ON stroke (round_id, position)');
        $this->addSql('CREATE INDEX IDX_94A5B72A6005CA0 ON stroke (round_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE guess');
        $this->addSql('DROP TABLE round');
        $this->addSql('DROP TABLE stroke');
    }
}
