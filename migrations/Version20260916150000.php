<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Dossiers documentaires » - the eight tables of a new tool
 * (design/design_handoff_dossier_documentaire).
 *
 * Nothing is seeded and nothing is switched on: App\Enum\Feature::Dossiers answers false for every
 * role, so the screens exist and only an administrator reaches them until somebody ticks a line in
 * Gestion > Fonctionnalités. There is therefore no matrix row to write here.
 *
 * Two shapes worth reading before changing them:
 *
 * - `dossier_document.dossier_group_id` is **ON DELETE SET NULL**, not CASCADE: deleting a group
 *   drops the title, never the documents, which fall back under « Hors groupe ».
 * - `dossier_submission` carries a unique index on (document, cible, version). Versions are rows -
 *   a correction is answered by a v2 next to the v1, not by an overwrite - and that index is what
 *   makes « the latest version » a question with one answer.
 *
 * No status column anywhere: where a cible stands on a document is derived from these rows and the
 * calendar by App\Service\Dossier\DossierStatusResolver.
 */
final class Version20260916150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossiers documentaires : dossier, groupes, documents, dépôts et échanges de validation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dossier_document (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, is_required TINYINT NOT NULL, deposit_type VARCHAR(20) NOT NULL, visible_from DATE NOT NULL, due_on DATE NOT NULL, late_allowed TINYINT NOT NULL, validation_profile VARCHAR(20) NOT NULL, position INT NOT NULL, dossier_id INT NOT NULL, dossier_group_id INT DEFAULT NULL, INDEX IDX_F0296801611C0C56 (dossier_id), INDEX IDX_F0296801D6143B30 (dossier_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, created_at DATETIME NOT NULL, state VARCHAR(20) NOT NULL, published_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_3D48E037B03A8386 (created_by_id), INDEX IDX_3D48E037F5A2E305 (inactivated_by_id), INDEX IDX_3D48E037E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_validator (dossier_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_34EB34B5611C0C56 (dossier_id), INDEX IDX_34EB34B5A76ED395 (user_id), PRIMARY KEY (dossier_id, user_id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_target_program (dossier_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_B0FC284C611C0C56 (dossier_id), INDEX IDX_B0FC284C3EB8070A (program_id), PRIMARY KEY (dossier_id, program_id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_target_student (dossier_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_9532F0FB611C0C56 (dossier_id), INDEX IDX_9532F0FBA76ED395 (user_id), PRIMARY KEY (dossier_id, user_id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_group (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, position INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_A4405E82611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_submission (id INT AUTO_INCREMENT NOT NULL, version INT NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, original_filename VARCHAR(255) DEFAULT NULL, byte_size INT DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, submitted_at DATETIME NOT NULL, document_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_F57F1ED6C33F7837 (document_id), INDEX IDX_F57F1ED6CB944F1A (student_id), INDEX dossier_submission_document_student_idx (document_id, student_id), UNIQUE INDEX dossier_submission_version_unique (document_id, student_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('CREATE TABLE dossier_review (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(30) NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, submission_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_9132D5EFE1FD4933 (submission_id), INDEX IDX_9132D5EFF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;');
        $this->addSql('ALTER TABLE dossier_document ADD CONSTRAINT FK_F0296801611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_document ADD CONSTRAINT FK_F0296801D6143B30 FOREIGN KEY (dossier_group_id) REFERENCES dossier_group (id) ON DELETE SET NULL;');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id);');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id);');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id);');
        $this->addSql('ALTER TABLE dossier_validator ADD CONSTRAINT FK_34EB34B5611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_validator ADD CONSTRAINT FK_34EB34B5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_target_program ADD CONSTRAINT FK_B0FC284C611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_target_program ADD CONSTRAINT FK_B0FC284C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_target_student ADD CONSTRAINT FK_9532F0FB611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_target_student ADD CONSTRAINT FK_9532F0FBA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_group ADD CONSTRAINT FK_A4405E82611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_submission ADD CONSTRAINT FK_F57F1ED6C33F7837 FOREIGN KEY (document_id) REFERENCES dossier_document (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_submission ADD CONSTRAINT FK_F57F1ED6CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id);');
        $this->addSql('ALTER TABLE dossier_review ADD CONSTRAINT FK_9132D5EFE1FD4933 FOREIGN KEY (submission_id) REFERENCES dossier_submission (id) ON DELETE CASCADE;');
        $this->addSql('ALTER TABLE dossier_review ADD CONSTRAINT FK_9132D5EFF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id);');
    }

    public function down(Schema $schema): void
    {
        // Dropped children first: every foreign key below is a real constraint, and MySQL refuses a
        // DROP TABLE that another table still points at.
        $this->addSql('DROP TABLE dossier_review');
        $this->addSql('DROP TABLE dossier_submission');
        $this->addSql('DROP TABLE dossier_document');
        $this->addSql('DROP TABLE dossier_group');
        $this->addSql('DROP TABLE dossier_target_student');
        $this->addSql('DROP TABLE dossier_target_program');
        $this->addSql('DROP TABLE dossier_validator');
        $this->addSql('DROP TABLE dossier');
    }
}
