<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The matrix stops being a list of refusals and becomes a list of what the establishment runs.
 *
 * Everything is switched off for every role, and the exceptions are written out one by one. That is
 * the shape the catalogue itself now takes (App\Enum\Feature::defaultForRoles() names the six
 * role-blind survivors, defaultRoles() the role-specific ones), and it is the shape the decision
 * took: the school keeps its schooling in another application and runs a handful of areas here.
 *
 * **Pédagogie goes off entirely**, minus four entries asked for by name: « Travail à faire »,
 * « Documents partagés à une classe » and le wiki for the students, « Tirage au sort, création de
 * groupes » for the teachers. Nothing is deleted and nothing is unreachable for ever - an admin
 * ticks a line back on from Gestion > Fonctionnalités, or opens it to one person from their card in
 * the annuaire, which is what the individual derogation is for.
 *
 * Three other lines move at the same time, all towards the students: le Courrier école, les offres
 * de formation et postulations, la recherche d'emploi. Les machines virtuelles go to the two roles
 * that sit in a classroom. Admins lose nothing here and cannot: they read the whole catalogue
 * without consulting it.
 *
 * The values are spelled out rather than read from the enum, like the migration that set the first
 * defaults: a migration replays years later and must keep meaning what it meant the day it ran.
 */
final class Version20260825180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch the feature role matrix off by default, keeping only what the establishment runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE feature_role_setting SET enabled = 0');

        // Role-blind survivors: the planned evaluation, the support, and the whole alternance area.
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE feature IN ('evaluation_planning', 'support', 'ufa_booklet', 'my_alternance', 'tutor_evaluations', 'laptop_loans')");

        // The students' own set.
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` = 'ROLE_STUDENT' AND feature IN ('student_work', 'shared_documents', 'wiki', 'school_mail', 'training_offers', 'job_search', 'my_vms')");

        // The teachers'.
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` = 'ROLE_TEACHER' AND feature IN ('class_tools', 'my_vms')");

        // e-CO, unchanged: on for the role that exists for it.
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` = 'ROLE_ECO' AND feature = 'eco'");
    }

    /**
     * Back to the catalogue of §4 plus the wiki exception - the state this migration replaced,
     * rewritten here rather than left to the two earlier migrations, so that this file keeps
     * meaning what it meant on its own.
     *
     * It cannot restore what an admin ticked in between, and does not pretend to.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE feature_role_setting SET enabled = 1');

        foreach ([
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
        ] as $feature) {
            $this->addSql('UPDATE feature_role_setting SET enabled = 0 WHERE feature = :feature', ['feature' => $feature]);
        }

        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE feature = 'eco'");
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE feature = 'eco' AND `role` = 'ROLE_ECO'");
        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE feature = 'wiki' AND `role` = 'ROLE_TEACHER'");
    }
}
