<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Note négative sur erreurs » on a launched quiz: whether a wrong answer costs points, how much,
 * and whether the copy may end below zero.
 *
 * Every quiz already launched reads as "no penalty", which is what it was marked under - the flag
 * defaults to false and nothing recomputes an attempt that is already frozen. The other three
 * columns carry the same values the launch form opens on, so a row edited by hand later means
 * something rather than removing half a point per question.
 *
 * The DEFAULTs only serve the ALTER on a populated table and are dropped right after, the mapping
 * declaring its own.
 */
final class Version20260923103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : note négative sur erreurs (pénalité fixe ou au barème)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE quiz_instance ADD negative_marking TINYINT(1) DEFAULT 0 NOT NULL, ADD penalty_mode VARCHAR(16) DEFAULT 'fixed' NOT NULL, ADD penalty_points NUMERIC(5, 2) DEFAULT '0.50' NOT NULL, ADD penalty_percent SMALLINT UNSIGNED DEFAULT 50 NOT NULL, ADD negative_score_allowed TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql('ALTER TABLE quiz_instance ALTER penalty_mode DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance DROP negative_marking, DROP penalty_mode, DROP penalty_points, DROP penalty_percent, DROP negative_score_allowed');
    }
}
