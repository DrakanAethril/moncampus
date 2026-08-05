<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drafts, trash and the editable signature of the School mail box (screens 3b and 3f).
 *
 * `school_mail_draft` is a table of its own rather than a state on `email_message`: an EmailMessage
 * is the trace of a mail that really travelled, with a Message-ID, an `.eml` on S3 and a delivery
 * status. A draft has none of that and may never have any.
 *
 * `email_message.deleted_at` is a soft delete, and deliberately so: the `.eml` stays on S3, which is
 * the source of truth, and the teacher screens keep counting a mail the student tidied away - what
 * left for a company left for good.
 *
 * `school_mail_signature` only holds a row once a student has edited something. The school's default
 * is computed from civil status, programme and etu address, so "Restore the default signature"
 * deletes the row instead of rewriting it: the default has to keep following its sources.
 */
final class Version20260805121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds school_mail_draft, school_mail_signature and email_message.deleted_at (drafts, trash and signature).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE school_mail_draft (id INT AUTO_INCREMENT NOT NULL, recipient VARCHAR(255) DEFAULT NULL, subject LONGTEXT DEFAULT NULL, body LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, student_id INT NOT NULL, reply_to_id INT DEFAULT NULL, INDEX IDX_C71EB409FFDF7169 (reply_to_id), INDEX idx_school_mail_draft_student (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_mail_signature (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(255) DEFAULT NULL, program_label VARCHAR(255) DEFAULT NULL, email_address VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, linkedin_url VARCHAR(255) DEFAULT NULL, github_url VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, student_id INT NOT NULL, UNIQUE INDEX uniq_school_mail_signature_student (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE school_mail_draft ADD CONSTRAINT FK_C71EB409CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_mail_draft ADD CONSTRAINT FK_C71EB409FFDF7169 FOREIGN KEY (reply_to_id) REFERENCES email_message (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE school_mail_signature ADD CONSTRAINT FK_171F1025CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_message ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE school_mail_draft DROP FOREIGN KEY FK_C71EB409CB944F1A');
        $this->addSql('ALTER TABLE school_mail_draft DROP FOREIGN KEY FK_C71EB409FFDF7169');
        $this->addSql('ALTER TABLE school_mail_signature DROP FOREIGN KEY FK_171F1025CB944F1A');
        $this->addSql('DROP TABLE school_mail_draft');
        $this->addSql('DROP TABLE school_mail_signature');
        $this->addSql('ALTER TABLE email_message DROP deleted_at');
    }
}
