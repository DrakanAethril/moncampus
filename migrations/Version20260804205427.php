<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/**
 * Data model behind the "School mail & application tracking" screens
 * (design_handoff_stage_alternance).
 *
 * `job_application` groups by company: a send, its follow-up and the reply received form a single
 * application. It carries **no progress status** - the handoff forbids it (principle #1): the
 * platform gathers mails, it does not sort them. What the screens show is derived from the mails
 * and their SES events.
 *
 * `enterprise.email_domain` is the key to screen 3g's automatic linking. It stays empty for generic
 * domains (gmail...), where linking happens on the full address, otherwise every individual on the
 * same provider would become the same company.
 *
 * `job_search` only holds a row for closed searches: having no row is the normal state. It keeps
 * who closed it and when, a mistaken closure having to be explainable.
 */
final class Version20260804205427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Socle des démarches de candidature : job_application, job_search, rattachement des mails et domaine/ville sur enterprise.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job_application (id INT AUTO_INCREMENT NOT NULL, position VARCHAR(255) DEFAULT NULL, contact_name VARCHAR(255) DEFAULT NULL, origin VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, enterprise_id INT NOT NULL, INDEX idx_job_application_student (student_id), INDEX idx_job_application_enterprise (enterprise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE job_search (id INT AUTO_INCREMENT NOT NULL, closed_at DATETIME NOT NULL, student_id INT NOT NULL, closed_by_id INT DEFAULT NULL, INDEX IDX_E4F4F626E1FA7797 (closed_by_id), UNIQUE INDEX uniq_job_search_student (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE job_application ADD CONSTRAINT FK_C737C688CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE job_application ADD CONSTRAINT FK_C737C688A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id)');
        $this->addSql('ALTER TABLE job_search ADD CONSTRAINT FK_E4F4F626CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE job_search ADD CONSTRAINT FK_E4F4F626E1FA7797 FOREIGN KEY (closed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE email_message ADD job_application_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE email_message ADD CONSTRAINT FK_B7D58B0AC7A5A08 FOREIGN KEY (job_application_id) REFERENCES job_application (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_email_message_job_application ON email_message (job_application_id)');
        $this->addSql('ALTER TABLE enterprise ADD city VARCHAR(255) DEFAULT NULL, ADD email_domain VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application DROP FOREIGN KEY FK_C737C688CB944F1A');
        $this->addSql('ALTER TABLE job_application DROP FOREIGN KEY FK_C737C688A97D1AC3');
        $this->addSql('ALTER TABLE job_search DROP FOREIGN KEY FK_E4F4F626CB944F1A');
        $this->addSql('ALTER TABLE job_search DROP FOREIGN KEY FK_E4F4F626E1FA7797');
        $this->addSql('DROP TABLE job_application');
        $this->addSql('DROP TABLE job_search');
        $this->addSql('ALTER TABLE email_message DROP FOREIGN KEY FK_B7D58B0AC7A5A08');
        $this->addSql('DROP INDEX idx_email_message_job_application ON email_message');
        $this->addSql('ALTER TABLE email_message DROP job_application_id');
        $this->addSql('ALTER TABLE enterprise DROP city, DROP email_domain');
    }
}
