<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Word clouds open to everyone (design/validated/word-cloud-public.md): a cloud may belong to no
 * class and be answered at /cloud/{code}, a word may come from a guest known by its token's hash.
 */
final class Version20261112183612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nuage de mots public : classe facultative, code public, mots d\'invités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE word_cloud ADD is_public TINYINT DEFAULT 0 NOT NULL, ADD public_code VARCHAR(8) DEFAULT NULL, CHANGE program_id program_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_word_cloud_public_code ON word_cloud (public_code)');
        $this->addSql('ALTER TABLE word_cloud_submission ADD guest_token_hash VARCHAR(64) DEFAULT NULL, CHANGE student_id student_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_word_cloud_guest_word ON word_cloud_submission (word_cloud_id, guest_token_hash, normalized_text)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_word_cloud_public_code ON word_cloud');
        $this->addSql('ALTER TABLE word_cloud DROP is_public, DROP public_code, CHANGE program_id program_id INT NOT NULL');
        $this->addSql('DROP INDEX uniq_word_cloud_guest_word ON word_cloud_submission');
        $this->addSql('ALTER TABLE word_cloud_submission DROP guest_token_hash, CHANGE student_id student_id INT NOT NULL');
    }
}
