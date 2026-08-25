<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Feature;
use App\Repository\FeatureRoleSettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the matrix actually holds after every migration has replayed - the world an establishment
 * gets on the day of the deployment (design/validated/feature-access.md §4).
 *
 * Deliberately **not** a FunctionalTestCase: that base opens the whole catalogue in its setUp, so
 * that the role-axis tests keep pinning roles rather than settings. This one is about the settings,
 * so it reads the database as the migrations left it and touches nothing.
 *
 * It is the only thing that verifies the lot 5 migration at all. That migration writes some four
 * hundred booleans by hand, from a list written out rather than read from the enum - which is right,
 * a migration must keep meaning what it meant - and the price of writing it out is that a typo in it
 * is invisible. This is what makes it visible: the two lists are compared, and the enum is the one
 * §4 was transcribed into twice.
 *
 * The delivered figures are pinned too, plainly, because §4 ends on a sanity check that has to be
 * redone whenever a line moves: a student reads Travail à faire, Quiz, Support, Mon alternance,
 * Sondages and their machines - and not the timetable, the agenda, the messagerie or the annuaire.
 */
class FeatureDefaultsTest extends KernelTestCase
{
    public function testTheMatrixMatchesTheCatalogue(): void
    {
        self::bootKernel();
        $matrix = static::getContainer()->get(FeatureRoleSettingRepository::class)->matrix();

        $wrong = [];
        foreach (Feature::cases() as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $key = $feature->value.'|'.$role;

                if (!\array_key_exists($key, $matrix)) {
                    $wrong[] = sprintf('%s: no row at all', $key);

                    continue;
                }

                $expected = $feature->defaultForRole($role);
                if ($matrix[$key] !== $expected) {
                    $wrong[] = sprintf('%s: stored %s, catalogue says %s', $key, $matrix[$key] ? 'on' : 'off', $expected ? 'on' : 'off');
                }
            }
        }

        sort($wrong);

        $this->assertSame([], $wrong, sprintf(
            "The seeded matrix and App\\Enum\\Feature disagree on %d pair(s).\n\n".
            "Either the migration transcribed §4 wrongly, or a default moved in the enum without a\n".
            "migration to carry it to the establishments that already deployed.\n\n%s",
            \count($wrong),
            implode("\n", $wrong),
        ));
    }

    /**
     * The e-CO exception, stated on its own because it is the single per-role value in the whole
     * catalogue and the easiest thing to lose in a bulk edit.
     */
    public function testEcoIsOffEverywhereExceptItsOwnRole(): void
    {
        self::bootKernel();
        $matrix = static::getContainer()->get(FeatureRoleSettingRepository::class)->matrix();

        $this->assertTrue($matrix['eco|ROLE_ECO'] ?? false);

        foreach (Feature::managedRoles() as $role) {
            if ('ROLE_ECO' === $role) {
                continue;
            }

            $this->assertFalse($matrix['eco|'.$role] ?? true, 'e-CO must be off for '.$role);
        }
    }

    /**
     * What each role is delivered with - the sanity check of the whole matrix, which has to be
     * redone whenever a line moves. Here it is, redone on every run.
     *
     * Since 2026-08-25 the shape of that answer is inverted: the establishment runs a handful of
     * areas and everything else is off, so what is worth pinning is the short list each role keeps.
     */
    public function testWhatEachRoleIsDeliveredWith(): void
    {
        self::bootKernel();
        $matrix = static::getContainer()->get(FeatureRoleSettingRepository::class)->matrix();

        $reads = static fn (string $role, string $feature): bool => $matrix[$feature.'|'.$role] ?? false;

        // A student: their work, what a class shares with them, their wiki, their mailbox and the
        // two screens that go with it, their machines, the alternance and the support.
        foreach ([
            'student_work', 'shared_documents', 'wiki', 'school_mail', 'training_offers',
            'job_search', 'my_vms', 'ufa_booklet', 'my_alternance', 'support',
        ] as $feature) {
            $this->assertTrue($reads('ROLE_STUDENT', $feature), 'a student is delivered '.$feature);
        }
        foreach (['timetable', 'agenda', 'messaging', 'gradebook_student', 'directory', 'lesson_log', 'quiz_take', 'course_space', 'surveys'] as $feature) {
            $this->assertFalse($reads('ROLE_STUDENT', $feature), 'a student is not delivered '.$feature);
        }

        // A teacher: the two class tools, their machines, the alternance, the support. The rest of
        // Pédagogie is off - not because it does not work, but because nobody has asked to run it.
        foreach (['class_tools', 'my_vms', 'ufa_booklet', 'evaluation_planning', 'support'] as $feature) {
            $this->assertTrue($reads('ROLE_TEACHER', $feature), 'a teacher is delivered '.$feature);
        }
        foreach ([
            'student_work', 'wiki', 'quiz_library', 'quiz_live', 'progression', 'sequence_library',
            'video', 'audio', 'surveys', 'tsf_referential', 'lesson_log', 'gradebook_entry',
            'timetable', 'file_library', 'content_sharing', 'course_space', 'school_mail',
            'training_offers', 'job_search',
        ] as $feature) {
            $this->assertFalse($reads('ROLE_TEACHER', $feature), 'a teacher is not delivered '.$feature);
        }

        // Staff: the alternance area and the equipment, and that is all. The unlinked mail is not
        // theirs (§12.2), and neither are the offers or the job search any more - both went to the
        // students, and a staff member who has to read them gets an individual derogation.
        foreach (['ufa_booklet', 'laptop_loans', 'my_alternance', 'support'] as $feature) {
            $this->assertTrue($reads('ROLE_STAFF', $feature), 'staff are delivered '.$feature);
        }
        foreach ([
            'training_offers', 'job_search', 'my_vms', 'school_mail', 'school_mail_supervision',
            'infrastructure', 'guest_console', 'activity_history', 'directory',
        ] as $feature) {
            $this->assertFalse($reads('ROLE_STAFF', $feature), 'staff are not delivered '.$feature);
        }

        // A tutor: their evaluations, the booklet, the support, and nothing else that matters.
        foreach (['tutor_evaluations', 'ufa_booklet', 'support'] as $feature) {
            $this->assertTrue($reads('ROLE_TUTOR', $feature), 'a tutor is delivered '.$feature);
        }
    }
}
