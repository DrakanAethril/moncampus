<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727045706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add program_student_modality, the modality equivalent of program_student_option.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE program_student_modality (
              id INT AUTO_INCREMENT NOT NULL,
              program_id INT NOT NULL,
              student_id INT NOT NULL,
              modality_id INT NOT NULL,
              INDEX IDX_3530C6D33EB8070A (program_id),
              INDEX IDX_3530C6D3CB944F1A (student_id),
              INDEX IDX_3530C6D32D6D889B (modality_id),
              UNIQUE INDEX program_student_modality_unique (
                program_id, student_id, modality_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_student_modality
            ADD
              CONSTRAINT FK_3530C6D33EB8070A FOREIGN KEY (program_id) REFERENCES program (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_student_modality
            ADD
              CONSTRAINT FK_3530C6D3CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_student_modality
            ADD
              CONSTRAINT FK_3530C6D32D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program_student_modality DROP FOREIGN KEY FK_3530C6D33EB8070A');
        $this->addSql('ALTER TABLE program_student_modality DROP FOREIGN KEY FK_3530C6D3CB944F1A');
        $this->addSql('ALTER TABLE program_student_modality DROP FOREIGN KEY FK_3530C6D32D6D889B');
        $this->addSql('DROP TABLE program_student_modality');
    }
}
