<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The nickname an agent chooses for itself. Written by hand, see Version20261112144450.
 */
final class Version20261112150159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The nickname of the agent of a game';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN agent_nickname VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN agent_nickname');
    }
}
