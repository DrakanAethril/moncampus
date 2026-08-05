<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The practice application workflow (design_handoff_workflow_postulation, screens 7b-7c and 8a-8e).
 *
 * A student unlocks their school mailbox by applying to a fictitious offer and having four elements
 * validated by a teacher: the mail, the CV, the cover letter, the signature. Nothing is ever sent -
 * the mail is written, stored and reviewed on the platform.
 *
 * Two modelling choices carry the rules the handoff insists on:
 *
 * - `training_application_version` keeps every submission instead of overwriting one, because the
 *   review is a conversation ("compare with v1") and a resend has to be readable next to what it
 *   replaced;
 * - `training_application_review` records each verdict as a fact - which element, which version, by
 *   whom, when - rather than a mutable status per element. That is what makes "a validation once
 *   acquired stays acquired" true by construction, and what answers who validated what.
 */
final class Version20260805210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the training application workflow: offers, applications, versions and per-element reviews.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_offer (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, document_key VARCHAR(512) DEFAULT NULL, document_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_C60C0D53B03A8386 (created_by_id), INDEX IDX_C60C0D53F5A2E305 (inactivated_by_id), INDEX IDX_C60C0D53E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_offer_validator (training_offer_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_EC305437B4A36779 (training_offer_id), INDEX IDX_EC305437A76ED395 (user_id), PRIMARY KEY (training_offer_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_offer_group (training_offer_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_4A8643FBB4A36779 (training_offer_id), INDEX IDX_4A8643FBFE54D947 (group_id), PRIMARY KEY (training_offer_id, group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_application_version (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, subject LONGTEXT DEFAULT NULL, body LONGTEXT NOT NULL, signature_snapshot LONGTEXT DEFAULT NULL, cv_key VARCHAR(512) DEFAULT NULL, cv_name VARCHAR(255) DEFAULT NULL, cover_letter_key VARCHAR(512) DEFAULT NULL, cover_letter_name VARCHAR(255) DEFAULT NULL, submitted_at DATETIME NOT NULL, application_id INT NOT NULL, INDEX IDX_F7F6E05F3E030ACD (application_id), UNIQUE INDEX uniq_training_application_version (application_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_application (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, offer_id INT NOT NULL, INDEX IDX_30A71653C674EE (offer_id), INDEX idx_training_application_student (student_id), INDEX idx_training_application_state (state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_application_review (id INT AUTO_INCREMENT NOT NULL, element VARCHAR(20) NOT NULL, decision VARCHAR(20) NOT NULL, remark LONGTEXT DEFAULT NULL, version_number INT NOT NULL, decided_at DATETIME NOT NULL, application_id INT NOT NULL, validator_id INT DEFAULT NULL, INDEX IDX_21465661B0644AEC (validator_id), INDEX idx_training_application_review_application (application_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_offer ADD CONSTRAINT FK_C60C0D53B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE training_offer ADD CONSTRAINT FK_C60C0D53F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE training_offer ADD CONSTRAINT FK_C60C0D53E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE training_offer_validator ADD CONSTRAINT FK_EC305437B4A36779 FOREIGN KEY (training_offer_id) REFERENCES training_offer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_offer_validator ADD CONSTRAINT FK_EC305437A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_offer_group ADD CONSTRAINT FK_4A8643FBB4A36779 FOREIGN KEY (training_offer_id) REFERENCES training_offer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_offer_group ADD CONSTRAINT FK_4A8643FBFE54D947 FOREIGN KEY (group_id) REFERENCES `group` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_application_version ADD CONSTRAINT FK_F7F6E05F3E030ACD FOREIGN KEY (application_id) REFERENCES training_application (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_application ADD CONSTRAINT FK_30A716CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_application ADD CONSTRAINT FK_30A71653C674EE FOREIGN KEY (offer_id) REFERENCES training_offer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_application_review ADD CONSTRAINT FK_214656613E030ACD FOREIGN KEY (application_id) REFERENCES training_application (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_application_review ADD CONSTRAINT FK_21465661B0644AEC FOREIGN KEY (validator_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_offer_validator');
        $this->addSql('DROP TABLE training_offer_group');
        $this->addSql('DROP TABLE training_application_review');
        $this->addSql('DROP TABLE training_application_version');
        $this->addSql('DROP TABLE training_application');
        $this->addSql('DROP TABLE training_offer');
    }
}
