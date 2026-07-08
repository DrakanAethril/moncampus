<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708131252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add internship_program_info.cover_page_key/calendar_key for S3-backed booklet cover/calendar uploads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info ADD cover_page_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_program_info ADD calendar_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info DROP cover_page_key');
        $this->addSql('ALTER TABLE internship_program_info DROP calendar_key');
    }
}
