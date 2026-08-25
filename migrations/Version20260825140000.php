<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The real defaults of design/validated/feature-access.md §4 - the lot that changes what people see,
 * and the only one that does.
 *
 * It ships **alone**, deliberately, so it can be reverted alone. Everything before it left the matrix
 * switched entirely on: the tables, the resolver, the guard, the two administration screens, the
 * attribute on 139 controllers and the occurrences of §7.3 all landed without a single screen moving.
 * What this migration does is flip a few dozen booleans; if the establishment disagrees with one of
 * them, an admin unticks it on Paramètres > Fonctionnalités and nothing else has to be undone.
 *
 * The reasoning behind the values, in one line each:
 *
 * - **Scolarité is off.** The establishment keeps its timetable, its grades and its reporting in
 *   another application, which is the need this whole system exists for.
 * - **Pédagogie is on**, minus the areas nobody has asked for yet - the cahier de texte, the course
 *   space, the file library, the shared documents, the sharing between colleagues, the documentation
 *   base.
 * - **Vie scolaire is off**, apart from the support and the Courrier école, whose real gate is its
 *   formation (§12.1) and which starts closed everywhere on that axis.
 * - **Alternance is on**, entirely: it is the other half of what this establishment runs.
 * - **Technique is off**, apart from « Mes machines virtuelles ». The infrastructure, the machine
 *   console, the activity history and the unlinked mail are off on *every* role rather than removed
 *   from the catalogue - the visible result is the same (an admin has them by construction) and the
 *   individual derogation stays available the day one person has to be given one, without touching an
 *   LDAP group (§12.2).
 * - **e-CO is off everywhere except `ROLE_ECO`**, which is what that role is for.
 *
 * An admin loses nothing here and cannot: they read the whole catalogue without consulting it.
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch the feature role matrix to the catalogue defaults of the design (§4)';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->offEverywhere() as $feature) {
            $this->addSql('UPDATE feature_role_setting SET enabled = 0 WHERE feature = :feature', ['feature' => $feature]);
        }

        // e-CO: off for everybody, on for the role that exists for it.
        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE feature = 'eco'");
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE feature = 'eco' AND `role` = 'ROLE_ECO'");
    }

    /**
     * Reverting puts the matrix back where lot 1 left it - every pair on - rather than trying to
     * restore what an admin may have ticked since. That is the honest answer: this migration wrote
     * over their choices, and a down() cannot invent them back.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE feature_role_setting SET enabled = 1');
    }

    /**
     * The features §4 switches off on every role. Written out rather than read from App\Enum\Feature:
     * a migration replays years later and must keep meaning what it meant the day it was written.
     *
     * @return list<string>
     */
    private function offEverywhere(): array
    {
        return [
            // Pédagogie
            'lesson_log', 'course_space', 'file_library', 'shared_documents', 'content_sharing',
            'documentation',
            // Scolarité - kept elsewhere by the establishment
            'timetable', 'timetable_settings', 'gradebook_entry', 'gradebook_student',
            'self_assessment', 'program_reporting', 'program_exports', 'program_financial',
            'directory',
            // Vie scolaire et communication
            'agenda', 'announcements', 'messaging', 'school_mail_supervision', 'signup_lists',
            'help',
            // Technique
            'infrastructure', 'guest_console', 'activity_history',
        ];
    }
}
