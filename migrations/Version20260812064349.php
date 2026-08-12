<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The video module's own tables (point 5A), mirroring the audio ones.
 *
 * Nothing existing is altered: the audio module keeps its tables untouched, which is the whole
 * reason these are separate rather than a generalisation of them.
 */
final class Version20260812064349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Video resources, their files and the per-student watch progress (point 5A).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE video_resource (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, program_id INT NOT NULL, assignment_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_1413C6AA3EB8070A (program_id), INDEX IDX_1413C6AAD19302F8 (assignment_id), INDEX IDX_1413C6AAB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE video_resource_option (video_resource_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_7FE4BB20ABB02C84 (video_resource_id), INDEX IDX_7FE4BB20A7C41D6F (option_id), PRIMARY KEY (video_resource_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE video_resource_file (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, poster_storage_key VARCHAR(255) DEFAULT NULL, duration_seconds INT NOT NULL, file_size INT NOT NULL, original_name VARCHAR(255) NOT NULL, position INT NOT NULL, uploaded_at DATETIME NOT NULL, resource_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_88C5BA9A89329D25 (resource_id), INDEX IDX_88C5BA9AA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE video_watch_progress (id INT AUTO_INCREMENT NOT NULL, max_watched_percent INT NOT NULL, last_watched_at DATETIME DEFAULT NULL, file_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_6D2C84A793CB796C (file_id), INDEX IDX_6D2C84A7CB944F1A (student_id), UNIQUE INDEX uniq_video_watch_file_student (file_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE video_resource ADD CONSTRAINT FK_1413C6AA3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE video_resource ADD CONSTRAINT FK_1413C6AAD19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE video_resource ADD CONSTRAINT FK_1413C6AAB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE video_resource_option ADD CONSTRAINT FK_7FE4BB20ABB02C84 FOREIGN KEY (video_resource_id) REFERENCES video_resource (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_resource_option ADD CONSTRAINT FK_7FE4BB20A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_resource_file ADD CONSTRAINT FK_88C5BA9A89329D25 FOREIGN KEY (resource_id) REFERENCES video_resource (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_resource_file ADD CONSTRAINT FK_88C5BA9AA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE video_watch_progress ADD CONSTRAINT FK_6D2C84A793CB796C FOREIGN KEY (file_id) REFERENCES video_resource_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_watch_progress ADD CONSTRAINT FK_6D2C84A7CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video_resource DROP FOREIGN KEY FK_1413C6AA3EB8070A');
        $this->addSql('ALTER TABLE video_resource DROP FOREIGN KEY FK_1413C6AAD19302F8');
        $this->addSql('ALTER TABLE video_resource DROP FOREIGN KEY FK_1413C6AAB03A8386');
        $this->addSql('ALTER TABLE video_resource_option DROP FOREIGN KEY FK_7FE4BB20ABB02C84');
        $this->addSql('ALTER TABLE video_resource_option DROP FOREIGN KEY FK_7FE4BB20A7C41D6F');
        $this->addSql('ALTER TABLE video_resource_file DROP FOREIGN KEY FK_88C5BA9A89329D25');
        $this->addSql('ALTER TABLE video_resource_file DROP FOREIGN KEY FK_88C5BA9AA2B28FE8');
        $this->addSql('ALTER TABLE video_watch_progress DROP FOREIGN KEY FK_6D2C84A793CB796C');
        $this->addSql('ALTER TABLE video_watch_progress DROP FOREIGN KEY FK_6D2C84A7CB944F1A');
        $this->addSql('DROP TABLE video_resource');
        $this->addSql('DROP TABLE video_resource_option');
        $this->addSql('DROP TABLE video_resource_file');
        $this->addSql('DROP TABLE video_watch_progress');
    }
}
