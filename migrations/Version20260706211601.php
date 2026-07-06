<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706211601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE skill (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, professional LONGTEXT DEFAULT NULL, knowledge LONGTEXT DEFAULT NULL, performance LONGTEXT DEFAULT NULL, volume DOUBLE PRECISION DEFAULT NULL, period VARCHAR(255) DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, teacher_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_5E3DE4773EB8070A (program_id), INDEX IDX_5E3DE47741807E1D (teacher_id), INDEX IDX_5E3DE477B03A8386 (created_by_id), INDEX IDX_5E3DE477F5A2E305 (inactivated_by_id), INDEX IDX_5E3DE477E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE topic (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, target_cm_hours INT NOT NULL, target_td_hours INT NOT NULL, target_tp_hours INT NOT NULL, description LONGTEXT DEFAULT NULL, max_session_length INT DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, teacher_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_9D40DE1B3EB8070A (program_id), INDEX IDX_9D40DE1B41807E1D (teacher_id), INDEX IDX_9D40DE1BB03A8386 (created_by_id), INDEX IDX_9D40DE1BF5A2E305 (inactivated_by_id), INDEX IDX_9D40DE1BE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE4773EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE47741807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1B3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1B41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1BF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1BE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE4773EB8070A');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE47741807E1D');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477B03A8386');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477F5A2E305');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477E562D849');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1B3EB8070A');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1B41807E1D');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1BB03A8386');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1BF5A2E305');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1BE562D849');
        $this->addSql('DROP TABLE skill');
        $this->addSql('DROP TABLE topic');
    }
}
