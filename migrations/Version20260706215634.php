<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706215634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_financial_item (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, source VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, quantity NUMERIC(10, 2) DEFAULT NULL, value NUMERIC(10, 2) NOT NULL, lesson_type_id INT DEFAULT NULL, program_id INT NOT NULL, INDEX IDX_3105D13C3030DE34 (lesson_type_id), INDEX IDX_3105D13C3EB8070A (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_lesson_type_cost (id INT AUTO_INCREMENT NOT NULL, cost NUMERIC(10, 2) NOT NULL, program_id INT NOT NULL, lesson_type_id INT NOT NULL, INDEX IDX_1DC8BCF63EB8070A (program_id), INDEX IDX_1DC8BCF63030DE34 (lesson_type_id), UNIQUE INDEX program_lesson_type_unique (program_id, lesson_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_financial_item ADD CONSTRAINT FK_3105D13C3030DE34 FOREIGN KEY (lesson_type_id) REFERENCES lesson_type (id)');
        $this->addSql('ALTER TABLE program_financial_item ADD CONSTRAINT FK_3105D13C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_lesson_type_cost ADD CONSTRAINT FK_1DC8BCF63EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_lesson_type_cost ADD CONSTRAINT FK_1DC8BCF63030DE34 FOREIGN KEY (lesson_type_id) REFERENCES lesson_type (id)');
        $this->addSql('ALTER TABLE lesson_type ADD default_cost NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_financial_item DROP FOREIGN KEY FK_3105D13C3030DE34');
        $this->addSql('ALTER TABLE program_financial_item DROP FOREIGN KEY FK_3105D13C3EB8070A');
        $this->addSql('ALTER TABLE program_lesson_type_cost DROP FOREIGN KEY FK_1DC8BCF63EB8070A');
        $this->addSql('ALTER TABLE program_lesson_type_cost DROP FOREIGN KEY FK_1DC8BCF63030DE34');
        $this->addSql('DROP TABLE program_financial_item');
        $this->addSql('DROP TABLE program_lesson_type_cost');
        $this->addSql('ALTER TABLE lesson_type DROP default_cost');
    }
}
