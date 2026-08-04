<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/**
 * Modèle des écrans « Courrier école & suivi des candidatures »
 * (design_handoff_stage_alternance).
 *
 * `job_application` regroupe par entreprise : un envoi, sa relance et la réponse reçue forment une
 * seule démarche. Elle ne porte **aucun statut d'avancement** - le handoff l'interdit (principe
 * n°1) : la plateforme rassemble les mails, elle ne les classe pas. Ce qui s'affiche à l'écran se
 * déduit des mails et de leurs événements SES.
 *
 * `enterprise.email_domain` est la clé du rattachement automatique de l'écran 3g. Il reste vide
 * pour les domaines génériques (gmail…), où le rattachement se fait par adresse complète, sans
 * quoi tous les particuliers d'un même fournisseur deviendraient la même entreprise.
 *
 * `job_search` ne porte de ligne que pour les recherches closes : l'absence de ligne est l'état
 * normal. On y garde qui a clos et quand, une clôture par erreur devant pouvoir être expliquée.
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
