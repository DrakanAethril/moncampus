<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Dossiers intervenants » (design/validated/dossiers-intervenants.md): a dossier and its models
 * (one table - a model is a dossier with no teacher), their groups and pieces, the dépôts and the
 * administration's reviews. Deliberately apart from the `dossier_*` tables of the dossiers
 * documentaires: the access rule is the opposite, and the files are somebody's papers.
 */
final class Version20261010175002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Teacher dossiers: teacher_dossier, teacher_dossier_group, teacher_dossier_document, teacher_dossier_submission, teacher_dossier_review';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teacher_dossier (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, state VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, teacher_id INT DEFAULT NULL, template_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_F08A6D7641807E1D (teacher_id), INDEX IDX_F08A6D765DA0FB8 (template_id), INDEX IDX_F08A6D76B03A8386 (created_by_id), INDEX IDX_F08A6D76F5A2E305 (inactivated_by_id), INDEX IDX_F08A6D76E562D849 (last_updated_by_id), INDEX teacher_dossier_teacher_state_idx (teacher_id, state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_dossier_document (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, is_required TINYINT NOT NULL, deposit_type VARCHAR(20) NOT NULL, validation_profile VARCHAR(20) NOT NULL, due_on DATE DEFAULT NULL, position INT NOT NULL, dossier_id INT NOT NULL, dossier_group_id INT DEFAULT NULL, INDEX IDX_70A6C86611C0C56 (dossier_id), INDEX IDX_70A6C86D6143B30 (dossier_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_dossier_group (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, position INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_6C01D0F6611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_dossier_review (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(30) NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, submission_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_C6922144E1FD4933 (submission_id), INDEX IDX_C6922144F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_dossier_submission (id INT AUTO_INCREMENT NOT NULL, version INT NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, original_filename VARCHAR(255) DEFAULT NULL, byte_size INT DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, submitted_at DATETIME NOT NULL, document_id INT NOT NULL, submitted_by_id INT NOT NULL, INDEX IDX_86D02360C33F7837 (document_id), INDEX IDX_86D0236079F7D87D (submitted_by_id), UNIQUE INDEX teacher_dossier_submission_version_unique (document_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE teacher_dossier ADD CONSTRAINT FK_F08A6D7641807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE teacher_dossier ADD CONSTRAINT FK_F08A6D765DA0FB8 FOREIGN KEY (template_id) REFERENCES teacher_dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teacher_dossier ADD CONSTRAINT FK_F08A6D76B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE teacher_dossier ADD CONSTRAINT FK_F08A6D76F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE teacher_dossier ADD CONSTRAINT FK_F08A6D76E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE teacher_dossier_document ADD CONSTRAINT FK_70A6C86611C0C56 FOREIGN KEY (dossier_id) REFERENCES teacher_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_dossier_document ADD CONSTRAINT FK_70A6C86D6143B30 FOREIGN KEY (dossier_group_id) REFERENCES teacher_dossier_group (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teacher_dossier_group ADD CONSTRAINT FK_6C01D0F6611C0C56 FOREIGN KEY (dossier_id) REFERENCES teacher_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_dossier_review ADD CONSTRAINT FK_C6922144E1FD4933 FOREIGN KEY (submission_id) REFERENCES teacher_dossier_submission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_dossier_review ADD CONSTRAINT FK_C6922144F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE teacher_dossier_submission ADD CONSTRAINT FK_86D02360C33F7837 FOREIGN KEY (document_id) REFERENCES teacher_dossier_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_dossier_submission ADD CONSTRAINT FK_86D0236079F7D87D FOREIGN KEY (submitted_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_dossier DROP FOREIGN KEY FK_F08A6D7641807E1D');
        $this->addSql('ALTER TABLE teacher_dossier DROP FOREIGN KEY FK_F08A6D765DA0FB8');
        $this->addSql('ALTER TABLE teacher_dossier DROP FOREIGN KEY FK_F08A6D76B03A8386');
        $this->addSql('ALTER TABLE teacher_dossier DROP FOREIGN KEY FK_F08A6D76F5A2E305');
        $this->addSql('ALTER TABLE teacher_dossier DROP FOREIGN KEY FK_F08A6D76E562D849');
        $this->addSql('ALTER TABLE teacher_dossier_document DROP FOREIGN KEY FK_70A6C86611C0C56');
        $this->addSql('ALTER TABLE teacher_dossier_document DROP FOREIGN KEY FK_70A6C86D6143B30');
        $this->addSql('ALTER TABLE teacher_dossier_group DROP FOREIGN KEY FK_6C01D0F6611C0C56');
        $this->addSql('ALTER TABLE teacher_dossier_review DROP FOREIGN KEY FK_C6922144E1FD4933');
        $this->addSql('ALTER TABLE teacher_dossier_review DROP FOREIGN KEY FK_C6922144F675F31B');
        $this->addSql('ALTER TABLE teacher_dossier_submission DROP FOREIGN KEY FK_86D02360C33F7837');
        $this->addSql('ALTER TABLE teacher_dossier_submission DROP FOREIGN KEY FK_86D0236079F7D87D');
        $this->addSql('DROP TABLE teacher_dossier');
        $this->addSql('DROP TABLE teacher_dossier_document');
        $this->addSql('DROP TABLE teacher_dossier_group');
        $this->addSql('DROP TABLE teacher_dossier_review');
        $this->addSql('DROP TABLE teacher_dossier_submission');
    }
}
