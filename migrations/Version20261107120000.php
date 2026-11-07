<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public live contests (design/validated/quiz-live-public.md, 2026-11-07):
 *
 * - a contest belongs to a class or to none: `quiz_instance.program_id` may be null, for a live
 *   instance only;
 * - `quiz_live_session.is_public` opens it to whoever scans its QR code, `theme` names its dress,
 *   `removed_user_ids` the accounts the host took out of the waiting room - the existing contests
 *   stay class contests, in the default theme;
 * - a participant may be a guest: no account, a pseudonym, a drawn avatar and the hash of its
 *   browser's token.
 *
 * The defaults only serve the ALTER on rows already there; the entities set their own.
 */
final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Public live contests: classless instance, is_public, theme, removed accounts, guest participants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance CHANGE program_id program_id INT DEFAULT NULL');

        $this->addSql("ALTER TABLE quiz_live_session ADD is_public TINYINT DEFAULT 0 NOT NULL, ADD theme VARCHAR(32) DEFAULT 'pop' NOT NULL, ADD removed_user_ids JSON DEFAULT NULL");
        $this->addSql("UPDATE quiz_live_session SET removed_user_ids = '[]'");
        $this->addSql('ALTER TABLE quiz_live_session ALTER is_public DROP DEFAULT, ALTER theme DROP DEFAULT, MODIFY removed_user_ids JSON NOT NULL');

        $this->addSql('ALTER TABLE quiz_live_participant ADD guest_token_hash VARCHAR(64) DEFAULT NULL, ADD guest_avatar JSON DEFAULT NULL, CHANGE student_id student_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A945AC708EA4074B ON quiz_live_participant (guest_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_A945AC708EA4074B ON quiz_live_participant');
        $this->addSql('DELETE FROM quiz_live_participant WHERE student_id IS NULL');
        $this->addSql('ALTER TABLE quiz_live_participant DROP guest_token_hash, DROP guest_avatar, CHANGE student_id student_id INT NOT NULL');
        $this->addSql('ALTER TABLE quiz_live_session DROP is_public, DROP theme, DROP removed_user_ids');
        $this->addSql('DELETE FROM quiz_live_session WHERE quiz_instance_id IN (SELECT id FROM quiz_instance WHERE program_id IS NULL)');
        $this->addSql('DELETE FROM quiz_instance WHERE program_id IS NULL');
        $this->addSql('ALTER TABLE quiz_instance CHANGE program_id program_id INT NOT NULL');
    }
}
