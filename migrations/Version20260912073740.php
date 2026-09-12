<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Tirages enregistrés » of the random-draw tool: a teacher's named, resumable draw for one class.
 */
final class Version20260912073740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saved random draws (tool: tirage au sort)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE random_draw (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, allow_repeat TINYINT NOT NULL, drawn_student_ids JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, program_id INT NOT NULL, teacher_id INT NOT NULL, option_id INT DEFAULT NULL, INDEX IDX_118DDB663EB8070A (program_id), INDEX IDX_118DDB6641807E1D (teacher_id), INDEX IDX_118DDB66A7C41D6F (option_id), UNIQUE INDEX uniq_random_draw_program_teacher_name (program_id, teacher_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE random_draw ADD CONSTRAINT FK_118DDB663EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE random_draw ADD CONSTRAINT FK_118DDB6641807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE random_draw ADD CONSTRAINT FK_118DDB66A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE random_draw DROP FOREIGN KEY FK_118DDB663EB8070A');
        $this->addSql('ALTER TABLE random_draw DROP FOREIGN KEY FK_118DDB6641807E1D');
        $this->addSql('ALTER TABLE random_draw DROP FOREIGN KEY FK_118DDB66A7C41D6F');
        $this->addSql('DROP TABLE random_draw');
    }
}
