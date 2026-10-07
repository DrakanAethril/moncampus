<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ECF booklet: the dates of the titre (arrêté, J.O., date d'effet, template update) differ from one
 * option to the next, so they leave the formation's ECF settings for the certification
 * (« Dénomination »). Every certification of a formation starts from the dates its formation had.
 */
final class Version20261007182636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ECF booklet: the dates of the titre move to the certification, per option';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program_certification ADD decree_date DATE DEFAULT NULL, ADD journal_date DATE DEFAULT NULL, ADD effective_date DATE DEFAULT NULL, ADD model_updated_date DATE DEFAULT NULL');
        $this->addSql('UPDATE program_certification c INNER JOIN program_ecf_settings e ON e.program_id = c.program_id SET c.decree_date = e.decree_date, c.journal_date = e.journal_date, c.effective_date = e.effective_date, c.model_updated_date = e.model_updated_date');
        $this->addSql('ALTER TABLE program_ecf_settings DROP decree_date, DROP journal_date, DROP effective_date, DROP model_updated_date');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program_ecf_settings ADD decree_date DATE DEFAULT NULL, ADD journal_date DATE DEFAULT NULL, ADD effective_date DATE DEFAULT NULL, ADD model_updated_date DATE DEFAULT NULL');
        // One set of dates per formation again: the earliest of its certifications'.
        $this->addSql('UPDATE program_ecf_settings e INNER JOIN (SELECT program_id, MIN(decree_date) AS decree_date, MIN(journal_date) AS journal_date, MIN(effective_date) AS effective_date, MIN(model_updated_date) AS model_updated_date FROM program_certification GROUP BY program_id) c ON c.program_id = e.program_id SET e.decree_date = c.decree_date, e.journal_date = c.journal_date, e.effective_date = c.effective_date, e.model_updated_date = c.model_updated_date');
        $this->addSql('ALTER TABLE program_certification DROP decree_date, DROP journal_date, DROP effective_date, DROP model_updated_date');
    }
}
