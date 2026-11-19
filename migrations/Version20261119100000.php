<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM: the « Liste d'attente » situation is gone. The dossiers that stood there go back to
 * « Candidature en attente », the other waiting situation, and their journal says so. Portable SQL
 * (production runs MariaDB).
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM: dossiers on the waiting list become « Candidature en attente »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO crm_journal_entry (dossier_id, kind, text, author_name, author_id, occurred_on, created_at) SELECT id, 'system', 'Situation : Liste d''attente → Candidature en attente (la liste d''attente n''existe plus).', 'Formatool', NULL, CURRENT_DATE, CURRENT_TIMESTAMP FROM crm_dossier WHERE situation = 'waitlist'");
        $this->addSql("UPDATE crm_dossier SET situation = 'pending' WHERE situation = 'waitlist'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The dossiers that stood on the waiting list are no longer told apart.');
    }
}
