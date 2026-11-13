<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How many strokes of a round a status told the agent of. Down written by hand, see Version20261112144450.
 */
final class Version20261113080953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The strokes of a round the agent was told of';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round ADD COLUMN strokes_told_to_agent INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round DROP COLUMN strokes_told_to_agent');
    }
}
