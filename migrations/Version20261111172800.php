<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What the CRM des inscriptions ships with: the role matrix's `crm` line, **off for every role**
 * (the administrator has it by construction), the ten origins, and the thirty-two pieces of the
 * team's spreadsheet - the ones the import of that spreadsheet maps its columns onto.
 *
 * Data only, written out rather than read from the application: a migration says what was
 * delivered that day. « Jury d'admission » and « Stage 2 » start hidden: a formation that asks for
 * them (MCO 2, the Bachelor) opens them in its own pieces.
 */
final class Version20261111172800 extends AbstractMigration
{
    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    private const array ORIGINS = ['JPO', 'Immersion', 'Salon', 'Parcoursup', 'Hors Parcoursup', 'Site internet', 'Lycée Beaupeyrat', 'Redoublement', 'Autre', 'Inconnue'];

    /** code, label, short label, phase, kind, modalities, paths, foreign only, default constraint, future date, choices, last value completes, payment mode, position */
    private const array PIECES = [
        ['pre_enrolment', 'Pré-inscription', 'Pré-insc.', 'pre_enrolment', 'choice', '["initial", "apprenticeship", "other"]', '["enrolment"]', 0, 'required', 0, '["En ligne", "Papier"]', 0, 0, 1],
        ['apprentice_kit', 'Envoi du kit de l\'alternant', 'Kit', 'pre_enrolment', 'check', '["apprenticeship"]', '["enrolment"]', 0, 'required', 0, '[]', 0, 0, 2],
        ['second_year', 'Passage en 2e année', 'Passage', 'enrolment', 'choice', '["initial", "apprenticeship", "other"]', '["reenrolment"]', 0, 'required', 0, '["Oui", "Redoublement"]', 0, 0, 3],
        ['file_sent', 'Envoi du dossier', 'Envoi', 'enrolment', 'choice', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '["Mail", "Papier"]', 0, 0, 4],
        ['file_completed', 'Dossier complété', 'Complété', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 5],
        ['deposit', 'Frais de dossier / acompte', 'Acompte', 'enrolment', 'amount', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 1, 6],
        ['admission_notice', 'Avis d\'admission', 'Avis', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment"]', 0, 'required', 0, '[]', 0, 0, 7],
        ['attestation_sent', 'Envoi attestation', 'Attestation', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 1, 'required', 0, '[]', 0, 0, 8],
        ['visa', 'Point visa', 'Visa', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 1, 'required', 0, '[]', 0, 0, 9],
        ['arrival_date', 'Date d’arrivée', 'Arrivée', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 1, 'optional', 1, '[]', 0, 0, 10],
        ['convocation', 'Convocation', 'Convoc.', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 0, 'required', 1, '[]', 0, 0, 11],
        ['charlemagne_red', 'MAJ Charlemagne rouge', 'Charl. rouge', 'enrolment', 'check', '["initial", "apprenticeship", "other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 12],
        ['positioning_initial', 'Positionnement', 'Position.', 'documents', 'check', '["initial"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 13],
        ['pro_training_contract', 'Contrat de F° Pro', 'Contrat F° Pro', 'documents', 'check', '["initial"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 14],
        ['admission_jury', 'Jury d’admission', 'Jury', 'documents', 'check', '["initial"]', '["enrolment", "reenrolment"]', 0, 'hidden', 0, '[]', 0, 0, 15],
        ['internship_status', 'Statut du stage', 'Statut stage', 'documents', 'list', '["initial"]', '["enrolment", "reenrolment"]', 0, 'optional', 0, '["En recherche", "Entretien prévu", "Convention à venir", "Convention signée"]', 1, 0, 16],
        ['internship', 'Stage', 'Stage', 'documents', 'text', '["initial"]', '["enrolment", "reenrolment"]', 0, 'optional', 0, '[]', 0, 0, 17],
        ['internship_2', 'Stage 2', 'Stage 2', 'documents', 'text', '["initial"]', '["enrolment", "reenrolment"]', 0, 'hidden', 0, '[]', 0, 0, 18],
        ['apprenticeship_status', 'Statut de l’alternance', 'Statut alt.', 'documents', 'list', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'optional', 0, '["En recherche", "Entretiens en cours", "Promesse d’embauche", "Contrat signé"]', 1, 0, 19],
        ['company', 'Entreprise', 'Entreprise', 'documents', 'text', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 20],
        ['positioning_apprenticeship', 'Positionnement', 'Position.', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 21],
        ['dc', 'DC', 'DC', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 22],
        ['igc', 'IGC', 'IGC', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 23],
        ['fdp', 'FDP', 'FDP', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 24],
        ['ypareo', 'Saisie Yparéo', 'Yparéo', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 25],
        ['charlemagne_company', 'Saisie Charlemagne entreprise', 'Charl. ent.', 'documents', 'check', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 26],
        ['contract_dates', 'Dates du contrat', 'Dates contrat', 'documents', 'period', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 27],
        ['npec', 'NPEC', 'NPEC', 'documents', 'amount', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 28],
        ['cerfa_entry', 'Saisie du Cerfa', 'Cerfa', 'documents', 'list', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'optional', 0, '["Campus", "Entreprise", "OPCO"]', 0, 0, 29],
        ['subrogation', 'Subrogation', 'Subrogation', 'documents', 'list', '["apprenticeship"]', '["enrolment", "reenrolment"]', 0, 'optional', 0, '["Oui", "Non"]', 0, 0, 30],
        ['positioning_other', 'Positionnement', 'Position.', 'documents', 'check', '["other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 31],
        ['funding', 'Financement', 'Financement', 'documents', 'text', '["other"]', '["enrolment", "reenrolment"]', 0, 'required', 0, '[]', 0, 0, 32],
    ];

    public function getDescription(): string
    {
        return 'Seed the CRM des inscriptions: the crm feature off for every role, its origins and its pieces';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $role) {
            $this->addSql('INSERT INTO feature_role_setting (feature, role, enabled) VALUES (?, ?, 0)', ['crm', $role]);
        }

        foreach (self::ORIGINS as $position => $label) {
            $this->addSql('INSERT INTO crm_origin (label, position) VALUES (?, ?)', [$label, $position + 1]);
        }

        foreach (self::PIECES as $piece) {
            $this->addSql(
                'INSERT INTO crm_piece (code, label, short_label, phase, kind, modalities, paths, foreign_only, default_constraint, future_date_allowed, choices, last_value_completes, asks_payment_mode, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $piece,
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM crm_piece');
        $this->addSql('DELETE FROM crm_origin');
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'crm'");
    }
}
