<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The tips of the copilot to the player who draws, and the guesses of the AI it was told of.
 */
final class Version20261113193830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The tips of the copilot to the player who draws';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tip (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, text VARCHAR(120) NOT NULL, said_after_ms INTEGER NOT NULL, round_id BLOB NOT NULL, CONSTRAINT FK_4883B84CA6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4883B84CA6005CA0 ON tip (round_id)');
        $this->addSql('ALTER TABLE round ADD COLUMN ai_guesses_told_to_agent INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tip');
        // A drop, not the rebuild Doctrine writes for SQLite: dropping the round table would delete its strokes.
        $this->addSql('ALTER TABLE round DROP COLUMN ai_guesses_told_to_agent');
    }
}
