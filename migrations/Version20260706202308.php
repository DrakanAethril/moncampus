<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706202308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lesson_session (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, start_hour TIME NOT NULL, end_hour TIME NOT NULL, title VARCHAR(255) NOT NULL, program_id INT NOT NULL, teacher_id INT DEFAULT NULL, class_room_id INT DEFAULT NULL, lesson_type_id INT DEFAULT NULL, INDEX IDX_253887733EB8070A (program_id), INDEX IDX_2538877341807E1D (teacher_id), INDEX IDX_253887739162176F (class_room_id), INDEX IDX_253887733030DE34 (lesson_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lesson_session_option (lesson_session_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_7B3DA5AE6C36A50E (lesson_session_id), INDEX IDX_7B3DA5AEA7C41D6F (option_id), PRIMARY KEY (lesson_session_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_253887733EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_2538877341807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_253887739162176F FOREIGN KEY (class_room_id) REFERENCES room (id)');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_253887733030DE34 FOREIGN KEY (lesson_type_id) REFERENCES lesson_type (id)');
        $this->addSql('ALTER TABLE lesson_session_option ADD CONSTRAINT FK_7B3DA5AE6C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_session_option ADD CONSTRAINT FK_7B3DA5AEA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lesson_session DROP FOREIGN KEY FK_253887733EB8070A');
        $this->addSql('ALTER TABLE lesson_session DROP FOREIGN KEY FK_2538877341807E1D');
        $this->addSql('ALTER TABLE lesson_session DROP FOREIGN KEY FK_253887739162176F');
        $this->addSql('ALTER TABLE lesson_session DROP FOREIGN KEY FK_253887733030DE34');
        $this->addSql('ALTER TABLE lesson_session_option DROP FOREIGN KEY FK_7B3DA5AE6C36A50E');
        $this->addSql('ALTER TABLE lesson_session_option DROP FOREIGN KEY FK_7B3DA5AEA7C41D6F');
        $this->addSql('DROP TABLE lesson_session');
        $this->addSql('DROP TABLE lesson_session_option');
    }
}
