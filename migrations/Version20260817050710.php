<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The link: nine tables gain a nullable `library_node_id` (design/validated/file-library.md).
 *
 * **The row keeps its own `storage_key`, copied from the node.** That is what makes the whole feature
 * cheap: every reader - a Twig template, `file_url()`, the mobile API, the PDF exports, the mail
 * attachment builder - is untouched, because nothing about *reading* a file changes. This foreign key
 * answers one question, "where is this file used", with a real index and a real constraint.
 *
 * The alternative, one `file_library_link (node, target_type, target_id)` table, was rejected: it
 * reads well and it lies, because nothing deletes its rows when the host is deleted, so the usage
 * list slowly fills with usages that no longer exist.
 *
 * `ON DELETE SET NULL` on all nine, never CASCADE: removing the links is App\Service\FileLibraryLinks's
 * job, done deliberately when the teacher confirms « Supprimer partout ». A cascade would delete the
 * *host* row - the quiz question, the séquence resource, the video and its watch statistics - as a
 * database side effect nobody can see.
 */
final class Version20260817050710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le lien : library_node_id sur les neuf tables qui portent un fichier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment_attachment ADD CONSTRAINT FK_47FCBD643CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_47FCBD643CCC27E7 ON assignment_attachment (library_node_id)');
        $this->addSql('ALTER TABLE documentation_article_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE documentation_article_attachment ADD CONSTRAINT FK_83C4039C3CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_83C4039C3CCC27E7 ON documentation_article_attachment (library_node_id)');
        $this->addSql('ALTER TABLE lesson_log_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE lesson_log_attachment ADD CONSTRAINT FK_3D99DA473CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3D99DA473CCC27E7 ON lesson_log_attachment (library_node_id)');
        $this->addSql('ALTER TABLE library_resource ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A3205023CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9A3205023CCC27E7 ON library_resource (library_node_id)');
        $this->addSql('ALTER TABLE message_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_attachment ADD CONSTRAINT FK_B68FF5243CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B68FF5243CCC27E7 ON message_attachment (library_node_id)');
        $this->addSql('ALTER TABLE quiz_question ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_question ADD CONSTRAINT FK_6033B00B3CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6033B00B3CCC27E7 ON quiz_question (library_node_id)');
        $this->addSql('ALTER TABLE signup_list_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE signup_list_attachment ADD CONSTRAINT FK_E9F3E5023CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E9F3E5023CCC27E7 ON signup_list_attachment (library_node_id)');
        $this->addSql('ALTER TABLE video_resource_file ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE video_resource_file ADD CONSTRAINT FK_88C5BA9A3CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_88C5BA9A3CCC27E7 ON video_resource_file (library_node_id)');
        $this->addSql('ALTER TABLE wiki_attachment ADD library_node_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wiki_attachment ADD CONSTRAINT FK_45570D733CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_45570D733CCC27E7 ON wiki_attachment (library_node_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_attachment DROP FOREIGN KEY FK_47FCBD643CCC27E7');
        $this->addSql('DROP INDEX IDX_47FCBD643CCC27E7 ON assignment_attachment');
        $this->addSql('ALTER TABLE assignment_attachment DROP library_node_id');
        $this->addSql('ALTER TABLE documentation_article_attachment DROP FOREIGN KEY FK_83C4039C3CCC27E7');
        $this->addSql('DROP INDEX IDX_83C4039C3CCC27E7 ON documentation_article_attachment');
        $this->addSql('ALTER TABLE documentation_article_attachment DROP library_node_id');
        $this->addSql('ALTER TABLE lesson_log_attachment DROP FOREIGN KEY FK_3D99DA473CCC27E7');
        $this->addSql('DROP INDEX IDX_3D99DA473CCC27E7 ON lesson_log_attachment');
        $this->addSql('ALTER TABLE lesson_log_attachment DROP library_node_id');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A3205023CCC27E7');
        $this->addSql('DROP INDEX IDX_9A3205023CCC27E7 ON library_resource');
        $this->addSql('ALTER TABLE library_resource DROP library_node_id');
        $this->addSql('ALTER TABLE message_attachment DROP FOREIGN KEY FK_B68FF5243CCC27E7');
        $this->addSql('DROP INDEX IDX_B68FF5243CCC27E7 ON message_attachment');
        $this->addSql('ALTER TABLE message_attachment DROP library_node_id');
        $this->addSql('ALTER TABLE quiz_question DROP FOREIGN KEY FK_6033B00B3CCC27E7');
        $this->addSql('DROP INDEX IDX_6033B00B3CCC27E7 ON quiz_question');
        $this->addSql('ALTER TABLE quiz_question DROP library_node_id');
        $this->addSql('ALTER TABLE signup_list_attachment DROP FOREIGN KEY FK_E9F3E5023CCC27E7');
        $this->addSql('DROP INDEX IDX_E9F3E5023CCC27E7 ON signup_list_attachment');
        $this->addSql('ALTER TABLE signup_list_attachment DROP library_node_id');
        $this->addSql('ALTER TABLE video_resource_file DROP FOREIGN KEY FK_88C5BA9A3CCC27E7');
        $this->addSql('DROP INDEX IDX_88C5BA9A3CCC27E7 ON video_resource_file');
        $this->addSql('ALTER TABLE video_resource_file DROP library_node_id');
        $this->addSql('ALTER TABLE wiki_attachment DROP FOREIGN KEY FK_45570D733CCC27E7');
        $this->addSql('DROP INDEX IDX_45570D733CCC27E7 ON wiki_attachment');
        $this->addSql('ALTER TABLE wiki_attachment DROP library_node_id');
    }
}
