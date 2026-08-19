<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Class import - the two tables that make the follow-up screen survive the tab being closed.
 *
 * They exist because MonCampus never writes to LDAP: it drops a row in `ldap_manage_user` that a
 * script on the server picks up every minute. A session would not survive that wait, and the
 * operator has to be able to come back the next day and see which accounts the directory actually
 * created.
 *
 * What is deliberately NOT here is a state column. The state of each account is read live off
 * `ldap_manage_user.state` through `ldap_request_id`: nothing to synchronise, therefore nothing
 * that can fall out of sync. `ON DELETE SET NULL` on that column rather than CASCADE, because a
 * purged queue row must not take the history of the import with it - the line still says which
 * student was created, and by whom.
 *
 * `ON DELETE CASCADE` on `batch_id` for the opposite reason: a line is nothing without its batch.
 */
final class Version20260818112324 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Class import: the batch (student_import_batch) and its lines (student_import_batch_line)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE student_import_batch (id INT AUTO_INCREMENT NOT NULL, file_name VARCHAR(255) NOT NULL, imported_at DATETIME NOT NULL, created_count INT DEFAULT 0 NOT NULL, attached_count INT DEFAULT 0 NOT NULL, updated_count INT DEFAULT 0 NOT NULL, program_id INT NOT NULL, imported_by_id INT DEFAULT NULL, INDEX IDX_8C804CAD3EB8070A (program_id), INDEX IDX_8C804CAD74953CEA (imported_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE student_import_batch_line (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(20) NOT NULL, batch_id INT NOT NULL, user_id INT DEFAULT NULL, ldap_request_id INT UNSIGNED DEFAULT NULL, INDEX IDX_A347B488F39EBE7A (batch_id), INDEX IDX_A347B488A76ED395 (user_id), INDEX IDX_A347B488F8F494CC (ldap_request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE student_import_batch ADD CONSTRAINT FK_8C804CAD3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE student_import_batch ADD CONSTRAINT FK_8C804CAD74953CEA FOREIGN KEY (imported_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE student_import_batch_line ADD CONSTRAINT FK_A347B488F39EBE7A FOREIGN KEY (batch_id) REFERENCES student_import_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_import_batch_line ADD CONSTRAINT FK_A347B488A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE student_import_batch_line ADD CONSTRAINT FK_A347B488F8F494CC FOREIGN KEY (ldap_request_id) REFERENCES ldap_manage_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE student_import_batch DROP FOREIGN KEY FK_8C804CAD3EB8070A');
        $this->addSql('ALTER TABLE student_import_batch DROP FOREIGN KEY FK_8C804CAD74953CEA');
        $this->addSql('ALTER TABLE student_import_batch_line DROP FOREIGN KEY FK_A347B488F39EBE7A');
        $this->addSql('ALTER TABLE student_import_batch_line DROP FOREIGN KEY FK_A347B488A76ED395');
        $this->addSql('ALTER TABLE student_import_batch_line DROP FOREIGN KEY FK_A347B488F8F494CC');
        $this->addSql('DROP TABLE student_import_batch');
        $this->addSql('DROP TABLE student_import_batch_line');
    }
}
