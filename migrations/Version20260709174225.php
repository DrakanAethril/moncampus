<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709174225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add program_teacher_option, the teacher equivalent of program_student_option.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE program_teacher_option (
              id INT AUTO_INCREMENT NOT NULL,
              program_id INT NOT NULL,
              teacher_id INT NOT NULL,
              option_id INT NOT NULL,
              INDEX IDX_B17C80233EB8070A (program_id),
              INDEX IDX_B17C802341807E1D (teacher_id),
              INDEX IDX_B17C8023A7C41D6F (option_id),
              UNIQUE INDEX program_teacher_option_unique (
                program_id, teacher_id, option_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_teacher_option
            ADD
              CONSTRAINT FK_B17C80233EB8070A FOREIGN KEY (program_id) REFERENCES program (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_teacher_option
            ADD
              CONSTRAINT FK_B17C802341807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_teacher_option
            ADD
              CONSTRAINT FK_B17C8023A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program_teacher_option DROP FOREIGN KEY FK_B17C80233EB8070A');
        $this->addSql('ALTER TABLE program_teacher_option DROP FOREIGN KEY FK_B17C802341807E1D');
        $this->addSql('ALTER TABLE program_teacher_option DROP FOREIGN KEY FK_B17C8023A7C41D6F');
        $this->addSql('DROP TABLE program_teacher_option');
    }
}
