<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712045342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bloc (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, label VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_C778955AB03A8386 (created_by_id), INDEX IDX_C778955AF5A2E305 (inactivated_by_id), INDEX IDX_C778955AE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance_instance (id INT AUTO_INCREMENT NOT NULL, creation_date DATETIME NOT NULL, ordre INT NOT NULL, titre VARCHAR(255) NOT NULL, duree NUMERIC(10, 2) DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, avant_description LONGTEXT DEFAULT NULL, apres_description LONGTEXT DEFAULT NULL, program_id INT NOT NULL, sequence_instance_id INT DEFAULT NULL, source_template_id INT DEFAULT NULL, lesson_session_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_AE8B31A63EB8070A (program_id), INDEX IDX_AE8B31A69C94529B (sequence_instance_id), INDEX IDX_AE8B31A639A55F18 (source_template_id), UNIQUE INDEX UNIQ_AE8B31A66C36A50E (lesson_session_id), INDEX IDX_AE8B31A6B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance_phase_instance (id INT AUTO_INCREMENT NOT NULL, ordre INT NOT NULL, nom VARCHAR(255) NOT NULL, duree NUMERIC(10, 2) DEFAULT NULL, contenu LONGTEXT DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, enseignant LONGTEXT DEFAULT NULL, etudiant LONGTEXT DEFAULT NULL, moyens_supports LONGTEXT DEFAULT NULL, difficultes LONGTEXT DEFAULT NULL, seance_instance_id INT NOT NULL, INDEX IDX_3A382761783B956 (seance_instance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance_phase_template (id INT AUTO_INCREMENT NOT NULL, ordre INT NOT NULL, nom VARCHAR(255) NOT NULL, duree NUMERIC(10, 2) DEFAULT NULL, contenu LONGTEXT DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, enseignant LONGTEXT DEFAULT NULL, etudiant LONGTEXT DEFAULT NULL, moyens_supports LONGTEXT DEFAULT NULL, difficultes LONGTEXT DEFAULT NULL, seance_template_id INT NOT NULL, INDEX IDX_EF68893C3808C4F3 (seance_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance_template (id INT AUTO_INCREMENT NOT NULL, ordre INT NOT NULL, titre VARCHAR(255) NOT NULL, duree NUMERIC(10, 2) DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, avant_description LONGTEXT DEFAULT NULL, apres_description LONGTEXT DEFAULT NULL, is_optional TINYINT NOT NULL, optional_note LONGTEXT DEFAULT NULL, sequence_template_id INT NOT NULL, INDEX IDX_7BDB9FFBA31F2F3E (sequence_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sequence_instance (id INT AUTO_INCREMENT NOT NULL, creation_date DATETIME NOT NULL, titre VARCHAR(255) NOT NULL, capacites_attendues LONGTEXT DEFAULT NULL, pre_requis LONGTEXT DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, transversalites LONGTEXT DEFAULT NULL, situation_problematique LONGTEXT DEFAULT NULL, supports_generaux LONGTEXT DEFAULT NULL, program_id INT NOT NULL, source_template_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_CA0CE1F13EB8070A (program_id), INDEX IDX_CA0CE1F139A55F18 (source_template_id), INDEX IDX_CA0CE1F1B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sequence_template (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, capacites_attendues LONGTEXT DEFAULT NULL, pre_requis LONGTEXT DEFAULT NULL, objectifs LONGTEXT DEFAULT NULL, transversalites LONGTEXT DEFAULT NULL, situation_problematique LONGTEXT DEFAULT NULL, supports_generaux LONGTEXT DEFAULT NULL, creation_date DATETIME NOT NULL, teacher_id INT NOT NULL, cohort_id INT NOT NULL, option_id INT DEFAULT NULL, INDEX IDX_1F5C4FAC41807E1D (teacher_id), INDEX IDX_1F5C4FAC35983C93 (cohort_id), INDEX IDX_1F5C4FACA7C41D6F (option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sequence_template_bloc (sequence_template_id INT NOT NULL, bloc_id INT NOT NULL, INDEX IDX_440106F7A31F2F3E (sequence_template_id), INDEX IDX_440106F75582E9C0 (bloc_id), PRIMARY KEY (sequence_template_id, bloc_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT FK_C778955AB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT FK_C778955AF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT FK_C778955AE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE seance_instance ADD CONSTRAINT FK_AE8B31A63EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE seance_instance ADD CONSTRAINT FK_AE8B31A69C94529B FOREIGN KEY (sequence_instance_id) REFERENCES sequence_instance (id)');
        $this->addSql('ALTER TABLE seance_instance ADD CONSTRAINT FK_AE8B31A639A55F18 FOREIGN KEY (source_template_id) REFERENCES seance_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seance_instance ADD CONSTRAINT FK_AE8B31A66C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id)');
        $this->addSql('ALTER TABLE seance_instance ADD CONSTRAINT FK_AE8B31A6B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE seance_phase_instance ADD CONSTRAINT FK_3A382761783B956 FOREIGN KEY (seance_instance_id) REFERENCES seance_instance (id)');
        $this->addSql('ALTER TABLE seance_phase_template ADD CONSTRAINT FK_EF68893C3808C4F3 FOREIGN KEY (seance_template_id) REFERENCES seance_template (id)');
        $this->addSql('ALTER TABLE seance_template ADD CONSTRAINT FK_7BDB9FFBA31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id)');
        $this->addSql('ALTER TABLE sequence_instance ADD CONSTRAINT FK_CA0CE1F13EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE sequence_instance ADD CONSTRAINT FK_CA0CE1F139A55F18 FOREIGN KEY (source_template_id) REFERENCES sequence_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sequence_instance ADD CONSTRAINT FK_CA0CE1F1B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FAC41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FAC35983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id)');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FACA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE sequence_template_bloc ADD CONSTRAINT FK_440106F7A31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_template_bloc ADD CONSTRAINT FK_440106F75582E9C0 FOREIGN KEY (bloc_id) REFERENCES bloc (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY FK_C778955AB03A8386');
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY FK_C778955AF5A2E305');
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY FK_C778955AE562D849');
        $this->addSql('ALTER TABLE seance_instance DROP FOREIGN KEY FK_AE8B31A63EB8070A');
        $this->addSql('ALTER TABLE seance_instance DROP FOREIGN KEY FK_AE8B31A69C94529B');
        $this->addSql('ALTER TABLE seance_instance DROP FOREIGN KEY FK_AE8B31A639A55F18');
        $this->addSql('ALTER TABLE seance_instance DROP FOREIGN KEY FK_AE8B31A66C36A50E');
        $this->addSql('ALTER TABLE seance_instance DROP FOREIGN KEY FK_AE8B31A6B03A8386');
        $this->addSql('ALTER TABLE seance_phase_instance DROP FOREIGN KEY FK_3A382761783B956');
        $this->addSql('ALTER TABLE seance_phase_template DROP FOREIGN KEY FK_EF68893C3808C4F3');
        $this->addSql('ALTER TABLE seance_template DROP FOREIGN KEY FK_7BDB9FFBA31F2F3E');
        $this->addSql('ALTER TABLE sequence_instance DROP FOREIGN KEY FK_CA0CE1F13EB8070A');
        $this->addSql('ALTER TABLE sequence_instance DROP FOREIGN KEY FK_CA0CE1F139A55F18');
        $this->addSql('ALTER TABLE sequence_instance DROP FOREIGN KEY FK_CA0CE1F1B03A8386');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FAC41807E1D');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FAC35983C93');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FACA7C41D6F');
        $this->addSql('ALTER TABLE sequence_template_bloc DROP FOREIGN KEY FK_440106F7A31F2F3E');
        $this->addSql('ALTER TABLE sequence_template_bloc DROP FOREIGN KEY FK_440106F75582E9C0');
        $this->addSql('DROP TABLE bloc');
        $this->addSql('DROP TABLE seance_instance');
        $this->addSql('DROP TABLE seance_phase_instance');
        $this->addSql('DROP TABLE seance_phase_template');
        $this->addSql('DROP TABLE seance_template');
        $this->addSql('DROP TABLE sequence_instance');
        $this->addSql('DROP TABLE sequence_template');
        $this->addSql('DROP TABLE sequence_template_bloc');
    }
}
