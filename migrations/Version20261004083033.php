<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ECF booklet (design/validated/ecf-booklet.md): a formation's settings, the candidate's
 * booklet, its activity sheets, their evaluation rows and the visas.
 */
final class Version20261004083033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ECF booklet: settings, booklets, activities, evaluation rows, visas';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ecf_activity (id INT AUTO_INCREMENT NOT NULL, group_code VARCHAR(20) NOT NULL, result VARCHAR(20) DEFAULT NULL, complementary_result VARCHAR(20) DEFAULT NULL, attention_points LONGTEXT DEFAULT NULL, reassess_note LONGTEXT DEFAULT NULL, reassess_competences JSON NOT NULL, complementary_observations LONGTEXT DEFAULT NULL, frozen_label VARCHAR(255) DEFAULT NULL, frozen_competences JSON DEFAULT NULL, booklet_id INT NOT NULL, INDEX IDX_BA5601ED668144B3 (booklet_id), UNIQUE INDEX uniq_ecf_activity_booklet_code (booklet_id, group_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ecf_booklet (id INT AUTO_INCREMENT NOT NULL, title_code VARCHAR(30) NOT NULL, millesime VARCHAR(10) NOT NULL, civility VARCHAR(10) DEFAULT NULL, birth_date DATE DEFAULT NULL, synthesis_observations LONGTEXT DEFAULT NULL, remitted_on DATE DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, student_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_CF4A45FFCB944F1A (student_id), INDEX IDX_CF4A45FFB03A8386 (created_by_id), INDEX IDX_CF4A45FFF5A2E305 (inactivated_by_id), INDEX IDX_CF4A45FFE562D849 (last_updated_by_id), UNIQUE INDEX uniq_ecf_booklet_student_title (student_id, title_code, millesime), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ecf_evaluation_row (id INT AUTO_INCREMENT NOT NULL, part VARCHAR(20) NOT NULL, position INT NOT NULL, description LONGTEXT NOT NULL, evaluated_on DATE DEFAULT NULL, competences JSON NOT NULL, activity_id INT NOT NULL, INDEX IDX_5531C6C081C06096 (activity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ecf_visa (id INT AUTO_INCREMENT NOT NULL, part VARCHAR(20) NOT NULL, slot VARCHAR(20) NOT NULL, signer_name VARCHAR(120) NOT NULL, evaluated_on DATE NOT NULL, signed_at DATETIME NOT NULL, booklet_id INT NOT NULL, activity_id INT DEFAULT NULL, signer_id INT NOT NULL, INDEX IDX_146CE787668144B3 (booklet_id), INDEX IDX_146CE78781C06096 (activity_id), INDEX IDX_146CE7879588C067 (signer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_ecf_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT DEFAULT 0 NOT NULL, title_label VARCHAR(255) DEFAULT NULL, sigle VARCHAR(20) DEFAULT NULL, level VARCHAR(10) DEFAULT NULL, title_code VARCHAR(30) DEFAULT NULL, millesime VARCHAR(10) DEFAULT NULL, decree_date DATE DEFAULT NULL, journal_date DATE DEFAULT NULL, effective_date DATE DEFAULT NULL, model_updated_date DATE DEFAULT NULL, organisation VARCHAR(255) DEFAULT NULL, place VARCHAR(255) DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_B0C25B553EB8070A (program_id), INDEX IDX_B0C25B55B03A8386 (created_by_id), INDEX IDX_B0C25B55F5A2E305 (inactivated_by_id), INDEX IDX_B0C25B55E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ecf_activity ADD CONSTRAINT FK_BA5601ED668144B3 FOREIGN KEY (booklet_id) REFERENCES ecf_booklet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecf_booklet ADD CONSTRAINT FK_CF4A45FFCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecf_booklet ADD CONSTRAINT FK_CF4A45FFB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE ecf_booklet ADD CONSTRAINT FK_CF4A45FFF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE ecf_booklet ADD CONSTRAINT FK_CF4A45FFE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE ecf_evaluation_row ADD CONSTRAINT FK_5531C6C081C06096 FOREIGN KEY (activity_id) REFERENCES ecf_activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecf_visa ADD CONSTRAINT FK_146CE787668144B3 FOREIGN KEY (booklet_id) REFERENCES ecf_booklet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecf_visa ADD CONSTRAINT FK_146CE78781C06096 FOREIGN KEY (activity_id) REFERENCES ecf_activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecf_visa ADD CONSTRAINT FK_146CE7879588C067 FOREIGN KEY (signer_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_ecf_settings ADD CONSTRAINT FK_B0C25B553EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_ecf_settings ADD CONSTRAINT FK_B0C25B55B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_ecf_settings ADD CONSTRAINT FK_B0C25B55F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_ecf_settings ADD CONSTRAINT FK_B0C25B55E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ecf_activity DROP FOREIGN KEY FK_BA5601ED668144B3');
        $this->addSql('ALTER TABLE ecf_booklet DROP FOREIGN KEY FK_CF4A45FFCB944F1A');
        $this->addSql('ALTER TABLE ecf_booklet DROP FOREIGN KEY FK_CF4A45FFB03A8386');
        $this->addSql('ALTER TABLE ecf_booklet DROP FOREIGN KEY FK_CF4A45FFF5A2E305');
        $this->addSql('ALTER TABLE ecf_booklet DROP FOREIGN KEY FK_CF4A45FFE562D849');
        $this->addSql('ALTER TABLE ecf_evaluation_row DROP FOREIGN KEY FK_5531C6C081C06096');
        $this->addSql('ALTER TABLE ecf_visa DROP FOREIGN KEY FK_146CE787668144B3');
        $this->addSql('ALTER TABLE ecf_visa DROP FOREIGN KEY FK_146CE78781C06096');
        $this->addSql('ALTER TABLE ecf_visa DROP FOREIGN KEY FK_146CE7879588C067');
        $this->addSql('ALTER TABLE program_ecf_settings DROP FOREIGN KEY FK_B0C25B553EB8070A');
        $this->addSql('ALTER TABLE program_ecf_settings DROP FOREIGN KEY FK_B0C25B55B03A8386');
        $this->addSql('ALTER TABLE program_ecf_settings DROP FOREIGN KEY FK_B0C25B55F5A2E305');
        $this->addSql('ALTER TABLE program_ecf_settings DROP FOREIGN KEY FK_B0C25B55E562D849');
        $this->addSql('DROP TABLE ecf_activity');
        $this->addSql('DROP TABLE ecf_booklet');
        $this->addSql('DROP TABLE ecf_evaluation_row');
        $this->addSql('DROP TABLE ecf_visa');
        $this->addSql('DROP TABLE program_ecf_settings');
    }
}
