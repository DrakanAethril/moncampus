<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What the rule found, and what a human decided about it.
 *
 * `flagged_count` is how many questions App\Service\QuizSupervisionAssessor marked « à vérifier » -
 * frozen at the conclusion of the attempt and re-read at display time. It classes the attempt on
 * the teacher's list; it is never a score and never a verdict.
 *
 * The four review columns are the one statement of the whole device that asserts something about
 * somebody, and they are signed and dated. No « a triché » boolean is stored anywhere, and nothing
 * here touches a mark: the disciplinary side belongs to the establishment.
 *
 * The `DEFAULT 0` serves the ALTER alone and is dropped right after - the PHP property carries it.
 */
final class Version20260826140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flagged question count and the signed review decision on quiz_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempt ADD flagged_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD reviewed_at DATETIME DEFAULT NULL, ADD reviewed_by_id INT DEFAULT NULL, ADD review_outcome VARCHAR(16) DEFAULT NULL, ADD review_note LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt ALTER flagged_count DROP DEFAULT');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AB6AFC6FC6B21F1 ON quiz_attempt (reviewed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempt DROP FOREIGN KEY FK_AB6AFC6FC6B21F1');
        $this->addSql('DROP INDEX IDX_AB6AFC6FC6B21F1 ON quiz_attempt');
        $this->addSql('ALTER TABLE quiz_attempt DROP flagged_count, DROP reviewed_at, DROP reviewed_by_id, DROP review_outcome, DROP review_note');
    }
}
