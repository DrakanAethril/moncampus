<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM: two more modalities, « Scolaire » and « Contrat pro ». A piece asked of every modality so
 * far is asked of them too; a piece of one modality only (the apprenticeship's company, the
 * initial training's internship…) is left as it is, for the team to tick in Réglages › Pièces.
 *
 * Production runs MariaDB, where a JSON column is a LONGTEXT and CAST(… AS JSON) does not exist:
 * the values are written as plain strings, which both servers accept.
 */
final class Version20261113110000 extends AbstractMigration
{
    private const string BEFORE = '["initial", "apprenticeship", "other"]';
    private const string AFTER = '["initial", "school", "apprenticeship", "professional", "other"]';

    public function getDescription(): string
    {
        return 'CRM: the school and professional-contract modalities, asked the pieces common to every modality';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE crm_piece SET modalities = ? WHERE JSON_CONTAINS(modalities, '\"initial\"') AND JSON_CONTAINS(modalities, '\"apprenticeship\"') AND JSON_CONTAINS(modalities, '\"other\"')",
            [self::AFTER],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE crm_piece SET modalities = ? WHERE JSON_CONTAINS(modalities, ?)',
            [self::BEFORE, self::AFTER],
        );
        foreach (['school', 'professional'] as $value) {
            $this->addSql("UPDATE crm_piece SET modalities = JSON_REMOVE(modalities, JSON_UNQUOTE(JSON_SEARCH(modalities, 'one', ?))) WHERE JSON_SEARCH(modalities, 'one', ?) IS NOT NULL", [$value, $value]);
        }
        $this->addSql("UPDATE crm_dossier SET modality = 'other' WHERE modality IN ('school', 'professional', 'unspecified')");
        $this->addSql("UPDATE crm_program SET default_modality = 'initial' WHERE default_modality IN ('school', 'professional', 'unspecified')");
        $this->addSql("UPDATE crm_modality_change SET modality = 'other' WHERE modality IN ('school', 'professional', 'unspecified')");
    }
}
