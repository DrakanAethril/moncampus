<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The vivier d'entreprises (design/validated/vivier-entreprises.md §5): the hostings of our
 * students - stage or alternance, never guessed - the contacts declared for a company, the teachers
 * who know it, the team's notes, and on `enterprise` the snapshot of what the register says about
 * its confirmed establishment. Nothing is filled in: the history arrives by hand or by import.
 */
final class Version20261001033310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vivier d\'entreprises: hostings, contacts, teacher contacts, team notes, registry snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE enterprise_contact (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, job_title VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, shareable_with_students TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, enterprise_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_289A5372A97D1AC3 (enterprise_id), INDEX IDX_289A5372B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enterprise_hosting (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, year_start INT NOT NULL, student_name VARCHAR(255) DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, missions VARCHAR(500) DEFAULT NULL, source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, inactive_date DATETIME DEFAULT NULL, enterprise_id INT NOT NULL, track_id INT NOT NULL, option_id INT DEFAULT NULL, student_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, inactivated_by_id INT DEFAULT NULL, INDEX IDX_8DEE3A905ED23C43 (track_id), INDEX IDX_8DEE3A90A7C41D6F (option_id), INDEX IDX_8DEE3A90CB944F1A (student_id), INDEX IDX_8DEE3A90E7A1254A (contact_id), INDEX IDX_8DEE3A90B03A8386 (created_by_id), INDEX IDX_8DEE3A90896DBBDE (updated_by_id), INDEX IDX_8DEE3A90F5A2E305 (inactivated_by_id), INDEX idx_enterprise_hosting_enterprise (enterprise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enterprise_note (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, enterprise_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_BB653808F675F31B (author_id), INDEX idx_enterprise_note_enterprise (enterprise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enterprise_teacher_contact (id INT AUTO_INCREMENT NOT NULL, note VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, enterprise_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_DEC39FA2A97D1AC3 (enterprise_id), INDEX IDX_DEC39FA241807E1D (teacher_id), UNIQUE INDEX uniq_enterprise_teacher_contact (enterprise_id, teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE enterprise_contact ADD CONSTRAINT FK_289A5372A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enterprise_contact ADD CONSTRAINT FK_289A5372B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A905ED23C43 FOREIGN KEY (track_id) REFERENCES track (id)');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90E7A1254A FOREIGN KEY (contact_id) REFERENCES enterprise_contact (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_hosting ADD CONSTRAINT FK_8DEE3A90F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_note ADD CONSTRAINT FK_BB653808A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enterprise_note ADD CONSTRAINT FK_BB653808F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise_teacher_contact ADD CONSTRAINT FK_DEC39FA2A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enterprise_teacher_contact ADD CONSTRAINT FK_DEC39FA241807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enterprise ADD postal_code VARCHAR(10) DEFAULT NULL, ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL, ADD naf_code VARCHAR(10) DEFAULT NULL, ADD registry_read_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enterprise_contact DROP FOREIGN KEY FK_289A5372A97D1AC3');
        $this->addSql('ALTER TABLE enterprise_contact DROP FOREIGN KEY FK_289A5372B03A8386');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90A97D1AC3');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A905ED23C43');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90A7C41D6F');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90CB944F1A');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90E7A1254A');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90B03A8386');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90896DBBDE');
        $this->addSql('ALTER TABLE enterprise_hosting DROP FOREIGN KEY FK_8DEE3A90F5A2E305');
        $this->addSql('ALTER TABLE enterprise_note DROP FOREIGN KEY FK_BB653808A97D1AC3');
        $this->addSql('ALTER TABLE enterprise_note DROP FOREIGN KEY FK_BB653808F675F31B');
        $this->addSql('ALTER TABLE enterprise_teacher_contact DROP FOREIGN KEY FK_DEC39FA2A97D1AC3');
        $this->addSql('ALTER TABLE enterprise_teacher_contact DROP FOREIGN KEY FK_DEC39FA241807E1D');
        $this->addSql('DROP TABLE enterprise_contact');
        $this->addSql('DROP TABLE enterprise_hosting');
        $this->addSql('DROP TABLE enterprise_note');
        $this->addSql('DROP TABLE enterprise_teacher_contact');
        $this->addSql('ALTER TABLE enterprise DROP postal_code, DROP latitude, DROP longitude, DROP naf_code, DROP registry_read_at');
    }
}
