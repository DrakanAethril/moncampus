<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Marquer terminé » now says what a search ended on - a stage or an alternance found, and the
 * démarche - which is how a closed search feeds the vivier (design/validated/vivier-entreprises.md
 * §6.4). Every search closed before stays without an outcome: nothing is guessed.
 */
final class Version20261001034423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Job search outcome: kind and démarche';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_search ADD outcome_kind VARCHAR(20) DEFAULT NULL, ADD outcome_application_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE job_search ADD CONSTRAINT FK_E4F4F626DC27D5A0 FOREIGN KEY (outcome_application_id) REFERENCES job_application (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E4F4F626DC27D5A0 ON job_search (outcome_application_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_search DROP FOREIGN KEY FK_E4F4F626DC27D5A0');
        $this->addSql('DROP INDEX IDX_E4F4F626DC27D5A0 ON job_search');
        $this->addSql('ALTER TABLE job_search DROP outcome_kind, DROP outcome_application_id');
    }
}
