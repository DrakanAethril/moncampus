<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712064042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add library_resource / library_resource_instance for the teaching-sequence library attachment layer';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE library_resource (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, creation_date DATETIME NOT NULL, teacher_id INT NOT NULL, cohort_id INT DEFAULT NULL, option_id INT DEFAULT NULL, sequence_template_id INT DEFAULT NULL, seance_template_id INT DEFAULT NULL, seance_phase_template_id INT DEFAULT NULL, INDEX IDX_9A32050241807E1D (teacher_id), INDEX IDX_9A32050235983C93 (cohort_id), INDEX IDX_9A320502A7C41D6F (option_id), INDEX IDX_9A320502A31F2F3E (sequence_template_id), INDEX IDX_9A3205023808C4F3 (seance_template_id), INDEX IDX_9A320502E2181343 (seance_phase_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_resource_bloc (library_resource_id INT NOT NULL, bloc_id INT NOT NULL, INDEX IDX_7B02157DD065B401 (library_resource_id), INDEX IDX_7B02157D5582E9C0 (bloc_id), PRIMARY KEY (library_resource_id, bloc_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_resource_instance (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, creation_date DATETIME NOT NULL, sequence_instance_id INT DEFAULT NULL, seance_instance_id INT DEFAULT NULL, seance_phase_instance_id INT DEFAULT NULL, INDEX IDX_89E753029C94529B (sequence_instance_id), INDEX IDX_89E75302783B956 (seance_instance_id), INDEX IDX_89E75302DD936EE6 (seance_phase_instance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A32050241807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A32050235983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A320502A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A320502A31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A3205023808C4F3 FOREIGN KEY (seance_template_id) REFERENCES seance_template (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A320502E2181343 FOREIGN KEY (seance_phase_template_id) REFERENCES seance_phase_template (id)');
        $this->addSql('ALTER TABLE library_resource_bloc ADD CONSTRAINT FK_7B02157DD065B401 FOREIGN KEY (library_resource_id) REFERENCES library_resource (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_bloc ADD CONSTRAINT FK_7B02157D5582E9C0 FOREIGN KEY (bloc_id) REFERENCES bloc (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_instance ADD CONSTRAINT FK_89E753029C94529B FOREIGN KEY (sequence_instance_id) REFERENCES sequence_instance (id)');
        $this->addSql('ALTER TABLE library_resource_instance ADD CONSTRAINT FK_89E75302783B956 FOREIGN KEY (seance_instance_id) REFERENCES seance_instance (id)');
        $this->addSql('ALTER TABLE library_resource_instance ADD CONSTRAINT FK_89E75302DD936EE6 FOREIGN KEY (seance_phase_instance_id) REFERENCES seance_phase_instance (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A32050241807E1D');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A32050235983C93');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A320502A7C41D6F');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A320502A31F2F3E');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A3205023808C4F3');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A320502E2181343');
        $this->addSql('ALTER TABLE library_resource_bloc DROP FOREIGN KEY FK_7B02157DD065B401');
        $this->addSql('ALTER TABLE library_resource_bloc DROP FOREIGN KEY FK_7B02157D5582E9C0');
        $this->addSql('ALTER TABLE library_resource_instance DROP FOREIGN KEY FK_89E753029C94529B');
        $this->addSql('ALTER TABLE library_resource_instance DROP FOREIGN KEY FK_89E75302783B956');
        $this->addSql('ALTER TABLE library_resource_instance DROP FOREIGN KEY FK_89E75302DD936EE6');
        $this->addSql('DROP TABLE library_resource');
        $this->addSql('DROP TABLE library_resource_bloc');
        $this->addSql('DROP TABLE library_resource_instance');
    }
}
