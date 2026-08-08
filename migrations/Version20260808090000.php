<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Practice applications: free attachments instead of a CV slot and a cover-letter slot
 * (design_handoff_postulation_redaction, screens 8b and 8f).
 *
 * A student now joins as many untyped files as they judge useful, so the two pairs of columns on
 * the version give way to a list. Nothing is lost on the way: every CV and every cover letter
 * already stored becomes a row of the new table, in that order - the CV first, as the compose
 * screen asked for it first - so a validator reopening an old application still finds both files
 * where they were, under the same names.
 *
 * The generated diff also carried an unrelated TINYINT default drift on `assignment`, dropped here
 * as it was in Version20260808052116: it is a mapping/schema mismatch predating this change.
 */
final class Version20260808090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Postulations : pièces jointes libres à la place des emplacements CV / lettre de motivation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_application_attachment (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(512) NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, version_id INT NOT NULL, INDEX idx_training_attachment_version (version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_application_attachment ADD CONSTRAINT FK_39E2F6314BBC2705 FOREIGN KEY (version_id) REFERENCES training_application_version (id) ON DELETE CASCADE');

        $this->addSql('INSERT INTO training_application_attachment (version_id, storage_key, name, position) SELECT id, cv_key, COALESCE(cv_name, cv_key), 0 FROM training_application_version WHERE cv_key IS NOT NULL');
        $this->addSql('INSERT INTO training_application_attachment (version_id, storage_key, name, position) SELECT id, cover_letter_key, COALESCE(cover_letter_name, cover_letter_key), 1 FROM training_application_version WHERE cover_letter_key IS NOT NULL');

        $this->addSql('ALTER TABLE training_application_version DROP cv_key, DROP cv_name, DROP cover_letter_key, DROP cover_letter_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_application_version ADD cv_key VARCHAR(512) DEFAULT NULL, ADD cv_name VARCHAR(255) DEFAULT NULL, ADD cover_letter_key VARCHAR(512) DEFAULT NULL, ADD cover_letter_name VARCHAR(255) DEFAULT NULL');

        // Only the first two files of a version can travel back, the shape they are going back into
        // holding no more than that.
        $this->addSql('UPDATE training_application_version v SET cv_key = (SELECT a.storage_key FROM training_application_attachment a WHERE a.version_id = v.id AND a.position = 0), cv_name = (SELECT a.name FROM training_application_attachment a WHERE a.version_id = v.id AND a.position = 0)');
        $this->addSql('UPDATE training_application_version v SET cover_letter_key = (SELECT a.storage_key FROM training_application_attachment a WHERE a.version_id = v.id AND a.position = 1), cover_letter_name = (SELECT a.name FROM training_application_attachment a WHERE a.version_id = v.id AND a.position = 1)');

        $this->addSql('DROP TABLE training_application_attachment');
    }
}
