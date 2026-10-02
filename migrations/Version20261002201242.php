<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Cours en ligne », lot 1 (design/validated/cours-en-ligne.md): a teacher's public page and the
 * addresses it has carried, the courses, their materials and each material's revisions, and the
 * author's own tags.
 */
final class Version20261002201242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Online courses: public page, courses, materials, revisions and tags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE online_course (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, slug VARCHAR(120) NOT NULL, summary VARCHAR(300) NOT NULL, description LONGTEXT DEFAULT NULL, estimated_minutes INT DEFAULT NULL, status VARCHAR(20) NOT NULL, published_at DATETIME DEFAULT NULL, storage_token VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_EEC37E177E3C61F9 (owner_id), INDEX idx_online_course_status (status), UNIQUE INDEX uniq_online_course_owner_slug (owner_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_tag_link (online_course_id INT NOT NULL, online_course_tag_id INT NOT NULL, INDEX IDX_56A330D9CCF9B759 (online_course_id), INDEX IDX_56A330D948A5EE63 (online_course_tag_id), PRIMARY KEY (online_course_id, online_course_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_material (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, label VARCHAR(100) DEFAULT NULL, slug VARCHAR(60) NOT NULL, position INT NOT NULL, storage_segment VARCHAR(16) NOT NULL, revision_counter INT NOT NULL, live_revision INT NOT NULL, created_at DATETIME NOT NULL, course_id INT NOT NULL, INDEX IDX_F2BE630B591CC992 (course_id), UNIQUE INDEX uniq_online_course_material_slug (course_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_material_revision (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, storage_prefix VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, file_size INT NOT NULL, file_count INT DEFAULT NULL, files JSON DEFAULT NULL, created_at DATETIME NOT NULL, material_id INT NOT NULL, INDEX IDX_B782E5C6E308AC6F (material_id), UNIQUE INDEX uniq_online_course_material_revision_number (material_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_page (id INT AUTO_INCREMENT NOT NULL, handle VARCHAR(60) NOT NULL, title VARCHAR(150) NOT NULL, introduction LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX uniq_online_course_page_owner (owner_id), UNIQUE INDEX uniq_online_course_page_handle (handle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_page_handle (id INT AUTO_INCREMENT NOT NULL, handle VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL, page_id INT NOT NULL, INDEX IDX_1543F664C4663E4 (page_id), UNIQUE INDEX uniq_online_course_page_handle_handle (handle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE online_course_tag (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, normalized_label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_7A26CFF77E3C61F9 (owner_id), UNIQUE INDEX uniq_online_course_tag_owner_label (owner_id, normalized_label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE online_course ADD CONSTRAINT FK_EEC37E177E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_tag_link ADD CONSTRAINT FK_56A330D9CCF9B759 FOREIGN KEY (online_course_id) REFERENCES online_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_tag_link ADD CONSTRAINT FK_56A330D948A5EE63 FOREIGN KEY (online_course_tag_id) REFERENCES online_course_tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_material ADD CONSTRAINT FK_F2BE630B591CC992 FOREIGN KEY (course_id) REFERENCES online_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_material_revision ADD CONSTRAINT FK_B782E5C6E308AC6F FOREIGN KEY (material_id) REFERENCES online_course_material (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_page ADD CONSTRAINT FK_431BAC7D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_page_handle ADD CONSTRAINT FK_1543F664C4663E4 FOREIGN KEY (page_id) REFERENCES online_course_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE online_course_tag ADD CONSTRAINT FK_7A26CFF77E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_course DROP FOREIGN KEY FK_EEC37E177E3C61F9');
        $this->addSql('ALTER TABLE online_course_tag_link DROP FOREIGN KEY FK_56A330D9CCF9B759');
        $this->addSql('ALTER TABLE online_course_tag_link DROP FOREIGN KEY FK_56A330D948A5EE63');
        $this->addSql('ALTER TABLE online_course_material DROP FOREIGN KEY FK_F2BE630B591CC992');
        $this->addSql('ALTER TABLE online_course_material_revision DROP FOREIGN KEY FK_B782E5C6E308AC6F');
        $this->addSql('ALTER TABLE online_course_page DROP FOREIGN KEY FK_431BAC7D7E3C61F9');
        $this->addSql('ALTER TABLE online_course_page_handle DROP FOREIGN KEY FK_1543F664C4663E4');
        $this->addSql('ALTER TABLE online_course_tag DROP FOREIGN KEY FK_7A26CFF77E3C61F9');
        $this->addSql('DROP TABLE online_course');
        $this->addSql('DROP TABLE online_course_tag_link');
        $this->addSql('DROP TABLE online_course_material');
        $this->addSql('DROP TABLE online_course_material_revision');
        $this->addSql('DROP TABLE online_course_page');
        $this->addSql('DROP TABLE online_course_page_handle');
        $this->addSql('DROP TABLE online_course_tag');
    }
}
