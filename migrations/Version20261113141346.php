<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The theme moves from the game to each round: the rounds of an existing game keep its theme.
 * SQLite rebuilds both tables; foreign keys are off meanwhile, or dropping the game table would
 * delete its rounds, and PRAGMA foreign_keys only works outside a transaction.
 */
final class Version20261113141346 extends AbstractMigration
{
    private const string ROUND_TABLE = 'CREATE TABLE round (id BLOB NOT NULL, started_at_ms BIGINT NOT NULL, found_after_ms INTEGER DEFAULT NULL, found_by VARCHAR(10) DEFAULT NULL, ai_drawing_started BOOLEAN DEFAULT 0 NOT NULL, ai_drawing_finished BOOLEAN DEFAULT 0 NOT NULL, given_up BOOLEAN DEFAULT 0 NOT NULL, drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL, strokes_seen_by_agent INTEGER DEFAULT 0 NOT NULL, strokes_told_to_agent INTEGER DEFAULT 0 NOT NULL, number INTEGER DEFAULT 1 NOT NULL, target_word VARCHAR(100) NOT NULL, duration_ms INTEGER DEFAULT 60000 NOT NULL, game_id BLOB NOT NULL, %s PRIMARY KEY (id), CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)';
    private const string ROUND_COLUMNS = 'id, started_at_ms, found_after_ms, found_by, ai_drawing_started, ai_drawing_finished, given_up, drawn_by, strokes_seen_by_agent, strokes_told_to_agent, number, target_word, duration_ms, game_id';
    private const string GAME_COLUMNS = 'id, created_at, language, round_count, first_drawn_by, copilot_name, copilot_seen_at, left_at, mode, agent_nickname';
    private const string GAME_TABLE = 'CREATE TABLE game (id BLOB NOT NULL, created_at DATETIME NOT NULL, language VARCHAR(255) NOT NULL, round_count INTEGER DEFAULT 1 NOT NULL, first_drawn_by VARCHAR(10) DEFAULT \'player\' NOT NULL, copilot_name VARCHAR(100) DEFAULT NULL, copilot_seen_at DATETIME DEFAULT NULL, left_at DATETIME DEFAULT NULL, mode VARCHAR(10) DEFAULT \'solo\' NOT NULL, agent_nickname VARCHAR(30) DEFAULT NULL, %s PRIMARY KEY (id))';

    public function getDescription(): string
    {
        return 'The theme of each round, dealt at random, instead of one theme for the game';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql('CREATE TEMPORARY TABLE __temp__round AS SELECT r.*, g.theme AS theme FROM round r JOIN game g ON g.id = r.game_id');
        $this->addSql('DROP TABLE round');
        $this->addSql(\sprintf(self::ROUND_TABLE, 'theme VARCHAR(255) NOT NULL,'));
        $this->addSql(\sprintf('INSERT INTO round (%1$s, theme) SELECT %1$s, theme FROM __temp__round', self::ROUND_COLUMNS));
        $this->addSql('DROP TABLE __temp__round');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5EEEA34E48FD90596901F54 ON round (game_id, number)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');

        $this->addSql(\sprintf('CREATE TEMPORARY TABLE __temp__game AS SELECT %s FROM game', self::GAME_COLUMNS));
        $this->addSql('DROP TABLE game');
        $this->addSql(\sprintf(self::GAME_TABLE, ''));
        $this->addSql(\sprintf('INSERT INTO game (%1$s) SELECT %1$s FROM __temp__game', self::GAME_COLUMNS));
        $this->addSql('DROP TABLE __temp__game');

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');

        // A game gets back the theme of its first round, or "animals" if it has none.
        $this->addSql(\sprintf('CREATE TEMPORARY TABLE __temp__game AS SELECT %s, COALESCE((SELECT r.theme FROM round r WHERE r.game_id = game.id ORDER BY r.number LIMIT 1), \'animals\') AS theme FROM game', self::GAME_COLUMNS));
        $this->addSql('DROP TABLE game');
        $this->addSql(\sprintf(self::GAME_TABLE, 'theme VARCHAR(255) NOT NULL,'));
        $this->addSql(\sprintf('INSERT INTO game (%1$s, theme) SELECT %1$s, theme FROM __temp__game', self::GAME_COLUMNS));
        $this->addSql('DROP TABLE __temp__game');

        $this->addSql(\sprintf('CREATE TEMPORARY TABLE __temp__round AS SELECT %s FROM round', self::ROUND_COLUMNS));
        $this->addSql('DROP TABLE round');
        $this->addSql(\sprintf(self::ROUND_TABLE, ''));
        $this->addSql(\sprintf('INSERT INTO round (%1$s) SELECT %1$s FROM __temp__round', self::ROUND_COLUMNS));
        $this->addSql('DROP TABLE __temp__round');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5EEEA34E48FD90596901F54 ON round (game_id, number)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
