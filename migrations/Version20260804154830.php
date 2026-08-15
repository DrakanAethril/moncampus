<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Foundation of the Courrier école: the students' school mailbox backed by SES/S3/SQS.
 *
 * `email_alias` carries the « local part → student » correspondence, indispensable because SES
 * reception is catch-all: the worker receives any address of the domain and must resolve its owner
 * by correspondence, never by guessing from the login.
 *
 * The two unique indexes of `email_message` (`message_id`, `source_key`) are not decorative: they are
 * what makes the processing idempotent in the face of SQS redeliveries, which are a certainty and not
 * an edge case.
 */
final class Version20260804154830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables du Courrier école (alias, messages, pièces jointes, événements, liste de suppression).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_alias (id INT AUTO_INCREMENT NOT NULL, local_part VARCHAR(64) NOT NULL, is_primary TINYINT NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_email_alias_user (user_id), UNIQUE INDEX uniq_email_alias_local_part (local_part), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_attachment (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, s3_key VARCHAR(512) NOT NULL, content_hash VARCHAR(64) NOT NULL, size_bytes INT NOT NULL, content_type VARCHAR(255) DEFAULT NULL, scan_verdict VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, email_message_id INT NOT NULL, INDEX idx_email_attachment_hash (content_hash), INDEX idx_email_attachment_message (email_message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_event (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(255) NOT NULL, event_type VARCHAR(32) NOT NULL, payload JSON NOT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX idx_email_event_message_id (message_id), UNIQUE INDEX uniq_email_event_dedup (message_id, event_type, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_message (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, direction VARCHAR(20) NOT NULL, recipient_local_part VARCHAR(64) DEFAULT NULL, from_address VARCHAR(255) NOT NULL, from_name VARCHAR(255) DEFAULT NULL, to_addresses JSON NOT NULL, cc_addresses JSON NOT NULL, subject LONGTEXT DEFAULT NULL, text_body LONGTEXT DEFAULT NULL, html_body LONGTEXT DEFAULT NULL, s3_key VARCHAR(512) NOT NULL, source_key VARCHAR(255) DEFAULT NULL, in_reply_to VARCHAR(255) DEFAULT NULL, references_header LONGTEXT DEFAULT NULL, spam_verdict VARCHAR(20) DEFAULT NULL, virus_verdict VARCHAR(20) DEFAULT NULL, delivery_status VARCHAR(20) DEFAULT NULL, message_date DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, student_id INT DEFAULT NULL, INDEX idx_email_message_student (student_id), INDEX idx_email_message_in_reply_to (in_reply_to), INDEX idx_email_message_direction_date (direction, message_date), UNIQUE INDEX uniq_email_message_message_id (message_id), UNIQUE INDEX uniq_email_message_source_key (source_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_suppression (id INT AUTO_INCREMENT NOT NULL, address VARCHAR(255) NOT NULL, reason VARCHAR(20) NOT NULL, details LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_email_suppression_address (address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE email_alias ADD CONSTRAINT FK_7C212A1BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_attachment ADD CONSTRAINT FK_D5EC2B64FFC9E1F6 FOREIGN KEY (email_message_id) REFERENCES email_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_message ADD CONSTRAINT FK_B7D58B0CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_alias DROP FOREIGN KEY FK_7C212A1BA76ED395');
        $this->addSql('ALTER TABLE email_attachment DROP FOREIGN KEY FK_D5EC2B64FFC9E1F6');
        $this->addSql('ALTER TABLE email_message DROP FOREIGN KEY FK_B7D58B0CB944F1A');
        $this->addSql('DROP TABLE email_alias');
        $this->addSql('DROP TABLE email_attachment');
        $this->addSql('DROP TABLE email_event');
        $this->addSql('DROP TABLE email_message');
        $this->addSql('DROP TABLE email_suppression');
    }
}
