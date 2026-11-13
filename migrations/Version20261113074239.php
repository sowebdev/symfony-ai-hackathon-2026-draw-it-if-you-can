<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How many strokes the agent saw in a round. Down written by hand, see Version20261112144450.
 */
final class Version20261113074239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The strokes of a round the agent saw';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round ADD COLUMN strokes_seen_by_agent INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round DROP COLUMN strokes_seen_by_agent');
    }
}
