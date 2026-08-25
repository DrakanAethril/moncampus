<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The groundwork of the feature-access system (design/validated/feature-access.md, lot 1): the role
 * matrix, the individual derogations, and the formation column the Courrier école will use.
 *
 * **Everything is seeded ON, deliberately.** The catalogue's real defaults (§4) are switched on by
 * the lot 5 migration, on its own, so that it can be reverted on its own - and so that no screen
 * changes before the occurrences of §7.3 have been dealt with one by one. A half-extinguished
 * application with tabs that fail to load is exactly what flipping the defaults too early produces.
 *
 * The derogation table is created **empty** and stays that way until somebody opens a person's
 * card: « Par défaut » is the absence of a row, not a stored value, which is what keeps « par
 * défaut » meaning "whatever the matrix says today".
 *
 * `program.school_mail_enabled` arrives here rather than with lot 6 because the resolver reads the
 * formation axis from the day it exists, and a column that does not exist yet would close the
 * Courrier école for everybody the moment lot 3 marks its controllers - four lots before the lot
 * that is allowed to change what people see. It is therefore seeded **open** on the existing
 * formations, and lot 6 is what closes it everywhere per §12.1, together with the setting, the
 * guard and the help sentence that make that closure readable.
 */
final class Version20260825090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feature_role_setting and user_feature_access, seeded all-on, plus program.school_mail_enabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feature_role_setting (id INT AUTO_INCREMENT NOT NULL, feature VARCHAR(64) NOT NULL, `role` VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, UNIQUE INDEX uniq_feature_role (feature, `role`), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_feature_access (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, feature VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, INDEX IDX_14F912A3A76ED395 (user_id), UNIQUE INDEX uniq_user_feature (user_id, feature), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_feature_access ADD CONSTRAINT FK_14F912A3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');

        // Open everywhere, so nothing changes on screen before the lot that is allowed to change
        // it. Lot 6 flips both this default and every row, together with the setting and the guard
        // that make the closure readable (§12.1).
        $this->addSql('ALTER TABLE program ADD school_mail_enabled TINYINT(1) DEFAULT 1 NOT NULL');

        foreach ($this->featureKeys() as $feature) {
            foreach ($this->roles() as $role) {
                $this->addSql('INSERT INTO feature_role_setting (feature, `role`, enabled) VALUES (:feature, :role, 1)', [
                    'feature' => $feature,
                    'role' => $role,
                ]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program DROP school_mail_enabled');
        $this->addSql('ALTER TABLE user_feature_access DROP FOREIGN KEY FK_14F912A3A76ED395');
        $this->addSql('DROP TABLE user_feature_access');
        $this->addSql('DROP TABLE feature_role_setting');
    }

    /**
     * The catalogue as it stands on the day of this migration, written out rather than read from
     * App\Enum\Feature: a migration replays years later and must not change meaning because an
     * enum gained a case. A feature added afterwards simply has no row, and an absent pair falls
     * back on Feature::defaultForRoles() - which is the mechanism that makes it painless.
     *
     * @return list<string>
     */
    private function featureKeys(): array
    {
        return [
            'lesson_log', 'student_work', 'quiz_library', 'quiz_take', 'quiz_live', 'progression',
            'sequence_library', 'sequence_import', 'course_space', 'video', 'audio', 'file_library',
            'shared_documents', 'content_sharing', 'wiki', 'documentation', 'surveys', 'class_tools',
            'tsf_referential',
            'timetable', 'timetable_settings', 'evaluation_planning', 'gradebook_entry',
            'gradebook_student', 'self_assessment', 'program_reporting', 'program_exports',
            'program_financial', 'directory',
            'agenda', 'announcements', 'messaging', 'school_mail', 'school_mail_supervision',
            'signup_lists', 'support', 'help',
            'ufa_booklet', 'my_alternance', 'tutor_evaluations', 'laptop_loans', 'training_offers',
            'job_search',
            'my_vms', 'infrastructure', 'guest_console', 'eco', 'activity_history',
        ];
    }

    /**
     * The columns of the matrix. `ROLE_ADMIN` is absent by construction: an admin has everything
     * without reading anything, and a column here would be the one way to lock the establishment
     * out of its own settings screen.
     *
     * @return list<string>
     */
    private function roles(): array
    {
        return [
            'ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD',
            'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL',
        ];
    }
}
