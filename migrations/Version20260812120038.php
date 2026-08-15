<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The medium an import named without being able to upload it.
 *
 * An AI cannot upload a file into the application: it names the one it was shown. The question is
 * created all the same and marked incomplete — the lock is at launch time, where a missing medium
 * would really break an attempt. The column is distinct from image_storage_key, which is the key of
 * an object already uploaded: attaching the file clears one and fills the other.
 *
 * Nullable column added: no existing row is rewritten.
 */
final class Version20260812120038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute quiz_question.expected_media_name (le média attendu par une question importée)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD expected_media_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP expected_media_name');
    }
}
