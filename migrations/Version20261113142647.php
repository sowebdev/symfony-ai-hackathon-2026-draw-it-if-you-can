<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The theme of the next round, dealt ahead: its card is shown while the AI picks the word.
 */
final class Version20261113142647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The theme of the next round, dealt ahead';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN next_theme VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // A drop, not the rebuild Doctrine writes for SQLite: dropping the game table would delete its rounds.
        $this->addSql('ALTER TABLE game DROP COLUMN next_theme');
    }
}
