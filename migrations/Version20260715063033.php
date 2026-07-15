<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715063033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop InternshipProgramInfo cover/calendar upload keys - replaced by the créa-based booklet cover and the Period-derived calendar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info DROP cover_page_key, DROP calendar_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info ADD cover_page_key VARCHAR(255) DEFAULT NULL, ADD calendar_key VARCHAR(255) DEFAULT NULL');
    }
}
