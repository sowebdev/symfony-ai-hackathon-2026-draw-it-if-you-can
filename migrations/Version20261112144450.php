<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The duel: the mode of a game, one agent per game, who said each word and who found it.
 * Written by hand: rebuilding the game table would delete its rounds, by the cascade.
 */
final class Version20261112144450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The mode of a game (solo, copilot, duel), its agent session, the author of a guess and who found a round';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game ADD COLUMN mode VARCHAR(10) DEFAULT 'solo' NOT NULL");
        $this->addSql("UPDATE game SET mode = 'copilot' WHERE with_copilot = 1");
        $this->addSql('ALTER TABLE game DROP COLUMN with_copilot');
        $this->addSql('ALTER TABLE game ADD COLUMN agent_session VARCHAR(36) DEFAULT NULL');

        // Before the duel, the AI guessed while the player drew, and the other way round.
        $this->addSql("ALTER TABLE guess ADD COLUMN author VARCHAR(10) DEFAULT 'player' NOT NULL");
        $this->addSql("UPDATE guess SET author = 'ai' WHERE round_id IN (SELECT id FROM round WHERE drawn_by = 'player')");
        $this->addSql('ALTER TABLE round ADD COLUMN found_by VARCHAR(10) DEFAULT NULL');
        $this->addSql("UPDATE round SET found_by = CASE drawn_by WHEN 'player' THEN 'ai' ELSE 'player' END WHERE found_after_ms IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round DROP COLUMN found_by');
        $this->addSql('ALTER TABLE guess DROP COLUMN author');
        $this->addSql('ALTER TABLE game DROP COLUMN agent_session');
        $this->addSql('ALTER TABLE game ADD COLUMN with_copilot BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE game SET with_copilot = 1 WHERE mode = 'copilot'");
        $this->addSql('ALTER TABLE game DROP COLUMN mode');
    }
}
