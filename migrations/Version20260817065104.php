<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Partager à une classe » - the tenth link onto file_library_node (App\Entity\SharedDocument).
 *
 * `ON DELETE CASCADE` on `library_node_id`, where the other nine links carry SET NULL, and the
 * difference is what the row *is*: those nine are host rows - a quiz question, a séquence resource -
 * that happen to point at a file and must survive it losing its picture. This row is the link and
 * nothing else, so a file that no longer exists leaves a share that means nothing. The cascade is a
 * backstop rather than the mechanism: App\Service\FileLibraryLinks::removeLinksTo() already removes
 * these rows when the teacher confirms « Supprimer partout », which is where the deletion is
 * deliberate and visible.
 *
 * `topic_id` is SET NULL for the opposite reason: a matière being deleted must not take the shares
 * filed under it with it - the student's list buckets those under « Sans matière » and keeps
 * serving the documents.
 *
 * The two join tables are plain sets with no payload: an empty one is « toute la classe », which is
 * why neither column is nullable and neither table has a row for "everybody".
 */
final class Version20260817065104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partage d’un document à une classe : shared_document et son ciblage options/modalités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_document (id INT AUTO_INCREMENT NOT NULL, visible_from DATETIME DEFAULT NULL, visible_until DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, library_node_id INT NOT NULL, teacher_id INT NOT NULL, program_id INT NOT NULL, topic_id INT DEFAULT NULL, INDEX IDX_8D4D7B551F55203D (topic_id), INDEX idx_shared_doc_program (program_id), INDEX idx_shared_doc_node (library_node_id), INDEX idx_shared_doc_teacher (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shared_document_option (shared_document_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_CED2E3FC890B4F6 (shared_document_id), INDEX IDX_CED2E3FA7C41D6F (option_id), PRIMARY KEY (shared_document_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shared_document_modality (shared_document_id INT NOT NULL, modality_id INT NOT NULL, INDEX IDX_50B457E0C890B4F6 (shared_document_id), INDEX IDX_50B457E02D6D889B (modality_id), PRIMARY KEY (shared_document_id, modality_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shared_document ADD CONSTRAINT FK_8D4D7B553CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shared_document ADD CONSTRAINT FK_8D4D7B5541807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE shared_document ADD CONSTRAINT FK_8D4D7B553EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE shared_document ADD CONSTRAINT FK_8D4D7B551F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shared_document_option ADD CONSTRAINT FK_CED2E3FC890B4F6 FOREIGN KEY (shared_document_id) REFERENCES shared_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shared_document_option ADD CONSTRAINT FK_CED2E3FA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shared_document_modality ADD CONSTRAINT FK_50B457E0C890B4F6 FOREIGN KEY (shared_document_id) REFERENCES shared_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shared_document_modality ADD CONSTRAINT FK_50B457E02D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_document DROP FOREIGN KEY FK_8D4D7B553CCC27E7');
        $this->addSql('ALTER TABLE shared_document DROP FOREIGN KEY FK_8D4D7B5541807E1D');
        $this->addSql('ALTER TABLE shared_document DROP FOREIGN KEY FK_8D4D7B553EB8070A');
        $this->addSql('ALTER TABLE shared_document DROP FOREIGN KEY FK_8D4D7B551F55203D');
        $this->addSql('ALTER TABLE shared_document_option DROP FOREIGN KEY FK_CED2E3FC890B4F6');
        $this->addSql('ALTER TABLE shared_document_option DROP FOREIGN KEY FK_CED2E3FA7C41D6F');
        $this->addSql('ALTER TABLE shared_document_modality DROP FOREIGN KEY FK_50B457E0C890B4F6');
        $this->addSql('ALTER TABLE shared_document_modality DROP FOREIGN KEY FK_50B457E02D6D889B');
        $this->addSql('DROP TABLE shared_document');
        $this->addSql('DROP TABLE shared_document_option');
        $this->addSql('DROP TABLE shared_document_modality');
    }
}
