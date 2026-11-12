<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No more MCP session on a game: the stateless protocol of recent clients has none, so one
 * agent per game cannot be told apart. Written by hand, see Version20261112144450.
 */
final class Version20261112145940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the MCP session of the agent of a game';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN agent_session');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN agent_session VARCHAR(36) DEFAULT NULL');
    }
}
