<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707044858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_report (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, day DATE NOT NULL, description LONGTEXT DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, referee_id INT NOT NULL, program_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_906F49DE4A087CA2 (referee_id), INDEX IDX_906F49DE3EB8070A (program_id), INDEX IDX_906F49DEB03A8386 (created_by_id), INDEX IDX_906F49DEF5A2E305 (inactivated_by_id), INDEX IDX_906F49DEE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_report_option (program_report_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_D63CC5B71DE0551F (program_report_id), INDEX IDX_D63CC5B7A7C41D6F (option_id), PRIMARY KEY (program_report_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_report ADD CONSTRAINT FK_906F49DE4A087CA2 FOREIGN KEY (referee_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_report ADD CONSTRAINT FK_906F49DE3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_report ADD CONSTRAINT FK_906F49DEB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_report ADD CONSTRAINT FK_906F49DEF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_report ADD CONSTRAINT FK_906F49DEE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_report_option ADD CONSTRAINT FK_D63CC5B71DE0551F FOREIGN KEY (program_report_id) REFERENCES program_report (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_report_option ADD CONSTRAINT FK_D63CC5B7A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_report DROP FOREIGN KEY FK_906F49DE4A087CA2');
        $this->addSql('ALTER TABLE program_report DROP FOREIGN KEY FK_906F49DE3EB8070A');
        $this->addSql('ALTER TABLE program_report DROP FOREIGN KEY FK_906F49DEB03A8386');
        $this->addSql('ALTER TABLE program_report DROP FOREIGN KEY FK_906F49DEF5A2E305');
        $this->addSql('ALTER TABLE program_report DROP FOREIGN KEY FK_906F49DEE562D849');
        $this->addSql('ALTER TABLE program_report_option DROP FOREIGN KEY FK_D63CC5B71DE0551F');
        $this->addSql('ALTER TABLE program_report_option DROP FOREIGN KEY FK_D63CC5B7A7C41D6F');
        $this->addSql('DROP TABLE program_report');
        $this->addSql('DROP TABLE program_report_option');
    }
}
