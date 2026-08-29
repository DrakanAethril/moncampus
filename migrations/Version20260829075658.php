<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One nullable column on `program`, holding the S3 key of the PDF uploaded in
 * UFA > Formations > « Documents » > « Emploi du temps ».
 *
 * Its own column rather than a row in a generic document table: there is one such document today,
 * and the shape of a second one is not known yet.
 */
final class Version20260829075658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UFA formation documents: a standalone timetable PDF, unrelated to the platform timetable';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program ADD timetable_document_file_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program DROP timetable_document_file_key');
    }
}
