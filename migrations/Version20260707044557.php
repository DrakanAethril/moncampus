<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707044557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_student_option (id INT AUTO_INCREMENT NOT NULL, program_id INT NOT NULL, student_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_2AC18A563EB8070A (program_id), INDEX IDX_2AC18A56CB944F1A (student_id), INDEX IDX_2AC18A56A7C41D6F (option_id), UNIQUE INDEX program_student_option_unique (program_id, student_id, option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_student_option ADD CONSTRAINT FK_2AC18A563EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_student_option ADD CONSTRAINT FK_2AC18A56CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_student_option ADD CONSTRAINT FK_2AC18A56A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_student_option DROP FOREIGN KEY FK_2AC18A563EB8070A');
        $this->addSql('ALTER TABLE program_student_option DROP FOREIGN KEY FK_2AC18A56CB944F1A');
        $this->addSql('ALTER TABLE program_student_option DROP FOREIGN KEY FK_2AC18A56A7C41D6F');
        $this->addSql('DROP TABLE program_student_option');
    }
}
