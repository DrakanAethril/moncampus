<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707181313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add internship_skill_group_option (0..many Options per skill group) and internship_option_exam_modality (per-Option exam modality override)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_option_exam_modality (id INT AUTO_INCREMENT NOT NULL, exam_modality_text LONGTEXT NOT NULL, program_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_C71888E33EB8070A (program_id), INDEX IDX_C71888E3A7C41D6F (option_id), UNIQUE INDEX internship_option_exam_modality_unique (program_id, option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_skill_group_option (internship_skill_group_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_17F7DFDE75BBCD97 (internship_skill_group_id), INDEX IDX_17F7DFDEA7C41D6F (option_id), PRIMARY KEY (internship_skill_group_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_option_exam_modality ADD CONSTRAINT FK_C71888E33EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_option_exam_modality ADD CONSTRAINT FK_C71888E3A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE internship_skill_group_option ADD CONSTRAINT FK_17F7DFDE75BBCD97 FOREIGN KEY (internship_skill_group_id) REFERENCES internship_skill_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE internship_skill_group_option ADD CONSTRAINT FK_17F7DFDEA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_option_exam_modality DROP FOREIGN KEY FK_C71888E33EB8070A');
        $this->addSql('ALTER TABLE internship_option_exam_modality DROP FOREIGN KEY FK_C71888E3A7C41D6F');
        $this->addSql('ALTER TABLE internship_skill_group_option DROP FOREIGN KEY FK_17F7DFDE75BBCD97');
        $this->addSql('ALTER TABLE internship_skill_group_option DROP FOREIGN KEY FK_17F7DFDEA7C41D6F');
        $this->addSql('DROP TABLE internship_option_exam_modality');
        $this->addSql('DROP TABLE internship_skill_group_option');
    }
}
