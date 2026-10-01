<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A démarche may now be « à écrire » - kept from « Trouver une entreprise » before any mail - and
 * carries the establishment it is about (SIRET, and the vivier's employer when known) and the
 * student's own note, read by their teachers (design/validated/vivier-entreprises.md §6). Nothing is
 * filled in for the existing démarches: a SIRET is never guessed.
 */
final class Version20261001033829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Job applications: SIRET, vivier employer, student note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application ADD siret VARCHAR(14) DEFAULT NULL, ADD student_note LONGTEXT DEFAULT NULL, ADD enterprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE job_application ADD CONSTRAINT FK_C737C688A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C737C688A97D1AC3 ON job_application (enterprise_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application DROP FOREIGN KEY FK_C737C688A97D1AC3');
        $this->addSql('DROP INDEX IDX_C737C688A97D1AC3 ON job_application');
        $this->addSql('ALTER TABLE job_application DROP siret, DROP student_note, DROP enterprise_id');
    }
}
