<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use App\Security\FeatureResolver;
use PHPUnit\Framework\TestCase;

/**
 * The resolution order of design/validated/feature-access.md §6, pinned on primitives.
 *
 * This is the one place in the design where a mistake is **silent**: a wrong order raises no
 * exception, it simply shows or hides one screen too many. Nothing here touches Doctrine or the
 * security token on purpose - App\Security\FeatureResolver takes a list of roles, a matrix, a table
 * of overrides and a set of formation flags, and answers. App\Security\FeatureAccess is the thin
 * shell that goes and fetches those four things.
 *
 * The six cases that matter, in the order the algorithm evaluates them:
 *
 *   1. the admin, who reads nothing;
 *   2. the extinguished parent, which wins over a child enabled by hand;
 *   3. the override, which comes before the formation flag;
 *   4. multi-role, most permissive wins;
 *   5. multi-formation, most permissive wins;
 *   6. an absent pair, which falls back on Feature::defaultForRoles().
 */
class FeatureResolverTest extends TestCase
{
    /**
     * @param list<string>                      $roles
     * @param array<string, bool>               $matrix       keyed "<feature>|<role>"
     * @param array<string, FeatureAccessState> $overrides    keyed by feature value
     * @param list<string>                      $openPrograms feature values at least one formation opens
     */
    private function resolver(array $roles, array $matrix = [], array $overrides = [], array $openPrograms = [], bool $isAdmin = false): FeatureResolver
    {
        return new FeatureResolver($isAdmin, $roles, $matrix, $overrides, $openPrograms);
    }

    /** @return array<string, bool> */
    private function matrixOf(Feature $feature, bool $enabled, string ...$roles): array
    {
        $matrix = [];
        foreach ($roles as $role) {
            $matrix[$feature->value.'|'.$role] = $enabled;
        }

        return $matrix;
    }

    // 1. The admin reads nothing at all.
    public function testAdminHasEverythingWithoutReadingAnything(): void
    {
        $resolver = $this->resolver(
            ['ROLE_ADMIN'],
            // Everything switched off for the role the admin also carries...
            [...$this->matrixOf(Feature::Agenda, false, 'ROLE_STAFF'), ...$this->matrixOf(Feature::SchoolMail, false, 'ROLE_STAFF')],
            // ...and switched off by hand on top of it.
            [Feature::Agenda->value => FeatureAccessState::Disabled],
            [],
            isAdmin: true,
        );

        $this->assertTrue($resolver->isEnabled(Feature::Agenda));
        // A program-scoped feature no formation opens: still true, since step 1 returns first.
        $this->assertTrue($resolver->isEnabled(Feature::SchoolMail));
        $this->assertSame([], array_filter($resolver->all(), static fn (bool $on): bool => !$on));
    }

    // 8.8 - the reason "the admin has everything" is not negotiable: no setting can close the
    // screen where the settings are made.
    public function testAdminCannotBeLockedOutOfAnything(): void
    {
        $matrix = [];
        $overrides = [];
        foreach (Feature::cases() as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $matrix[$feature->value.'|'.$role] = false;
            }
            $overrides[$feature->value] = FeatureAccessState::Disabled;
        }

        $resolver = $this->resolver(['ROLE_ADMIN'], $matrix, $overrides, [], isAdmin: true);

        foreach (Feature::cases() as $feature) {
            $this->assertTrue($resolver->isEnabled($feature), $feature->value.' must stay open to an admin');
        }
    }

    // 2. Parent off beats child on - including a child switched on by an individual override,
    // which is why the parent is evaluated first.
    public function testAnExtinguishedParentWinsOverAnEnabledChild(): void
    {
        $resolver = $this->resolver(
            ['ROLE_TEACHER'],
            [
                ...$this->matrixOf(Feature::GradebookEntry, false, 'ROLE_TEACHER'),
                ...$this->matrixOf(Feature::SelfAssessment, true, 'ROLE_TEACHER'),
            ],
            [Feature::SelfAssessment->value => FeatureAccessState::Enabled],
        );

        $this->assertFalse($resolver->isEnabled(Feature::GradebookEntry));
        $this->assertFalse($resolver->isEnabled(Feature::SelfAssessment));
    }

    public function testAChildFollowsItsParentWhenTheParentIsLit(): void
    {
        $resolver = $this->resolver(['ROLE_TEACHER'], [
            ...$this->matrixOf(Feature::GradebookEntry, true, 'ROLE_TEACHER'),
            ...$this->matrixOf(Feature::SelfAssessment, true, 'ROLE_TEACHER'),
        ]);

        $this->assertTrue($resolver->isEnabled(Feature::SelfAssessment));
    }

    // 3. The override comes before the formation flag - §3.5, the student looking for a company in
    // a formation whose Courrier pro is closed.
    public function testAnOverrideOpensAFeatureNoFormationOpens(): void
    {
        $resolver = $this->resolver(
            ['ROLE_STUDENT'],
            $this->matrixOf(Feature::SchoolMail, true, 'ROLE_STUDENT'),
            [Feature::SchoolMail->value => FeatureAccessState::Enabled],
            openPrograms: [],
        );

        $this->assertTrue($resolver->isEnabled(Feature::SchoolMail));
    }

    public function testAnOverrideClosesAFeatureTheFormationOpens(): void
    {
        $resolver = $this->resolver(
            ['ROLE_STUDENT'],
            $this->matrixOf(Feature::SchoolMail, true, 'ROLE_STUDENT'),
            [Feature::SchoolMail->value => FeatureAccessState::Disabled],
            openPrograms: [Feature::SchoolMail->value],
        );

        $this->assertFalse($resolver->isEnabled(Feature::SchoolMail));
    }

    // The override also comes before the matrix, in both directions.
    public function testAnOverrideBeatsTheMatrixBothWays(): void
    {
        $on = $this->resolver(
            ['ROLE_STUDENT'],
            $this->matrixOf(Feature::Agenda, false, 'ROLE_STUDENT'),
            [Feature::Agenda->value => FeatureAccessState::Enabled],
        );
        $off = $this->resolver(
            ['ROLE_STUDENT'],
            $this->matrixOf(Feature::Agenda, true, 'ROLE_STUDENT'),
            [Feature::Agenda->value => FeatureAccessState::Disabled],
        );

        $this->assertTrue($on->isEnabled(Feature::Agenda));
        $this->assertFalse($off->isEnabled(Feature::Agenda));
    }

    // 4. Multi-role: the most permissive wins - a teacher who becomes staff loses nothing (§3.3).
    public function testMultipleRolesResolveToTheMostPermissive(): void
    {
        $matrix = [
            ...$this->matrixOf(Feature::Progression, false, 'ROLE_STAFF'),
            ...$this->matrixOf(Feature::Progression, true, 'ROLE_TEACHER'),
        ];

        $this->assertTrue($this->resolver(['ROLE_TEACHER', 'ROLE_STAFF'], $matrix)->isEnabled(Feature::Progression));
        $this->assertFalse($this->resolver(['ROLE_STAFF'], $matrix)->isEnabled(Feature::Progression));
    }

    // 5. Multi-formation: the most permissive wins too - a mailbox cannot be partitioned by
    // formation, so one open formation is enough (§3.4).
    public function testOneOpenFormationIsEnough(): void
    {
        $matrix = $this->matrixOf(Feature::SchoolMail, true, 'ROLE_STUDENT');

        $open = $this->resolver(['ROLE_STUDENT'], $matrix, openPrograms: [Feature::SchoolMail->value]);
        $closed = $this->resolver(['ROLE_STUDENT'], $matrix, openPrograms: []);

        $this->assertTrue($open->isEnabled(Feature::SchoolMail));
        // The role matrix says yes and the formation says no: for a program-scoped feature the
        // formation is what answers (§6.4), which is what "OFF par formation" means in §4.
        $this->assertFalse($closed->isEnabled(Feature::SchoolMail));
    }

    // 6. An absent pair falls back on the catalogue's own default - which is what makes adding a
    // feature, or adding an LDAP role to the matrix, equally painless.
    public function testAnAbsentPairFallsBackOnTheCatalogueDefault(): void
    {
        $resolver = $this->resolver(['ROLE_STUDENT']);

        // Support rather than a Pédagogie entry: since the catalogue was inverted, almost
        // everything answers false role-blind, and a test of the fallback needs one of the six that
        // still answer true - otherwise it passes for the wrong reason.
        $this->assertTrue(Feature::Support->defaultForRoles());
        $this->assertTrue($resolver->isEnabled(Feature::Support));

        $this->assertFalse(Feature::Timetable->defaultForRoles());
        $this->assertFalse($resolver->isEnabled(Feature::Timetable));
    }

    // A role the matrix has no column for - ROLE_USER, the cohort roles - is not read: otherwise
    // unticking a feature for ROLE_STUDENT would change nothing, ROLE_USER falling back on the
    // catalogue default and re-opening it for everybody.
    public function testUnmanagedRolesAreNotRead(): void
    {
        $resolver = $this->resolver(
            ['ROLE_USER', 'ROLE_STUDENT', 'ROLE_SIO'],
            $this->matrixOf(Feature::StudentWork, false, 'ROLE_STUDENT'),
        );

        $this->assertFalse($resolver->isEnabled(Feature::StudentWork));
    }

    // Somebody carrying no managed role at all still gets an answer, and it is the catalogue's.
    public function testAnAccountWithNoManagedRoleFallsBackOnTheDefault(): void
    {
        $resolver = $this->resolver(['ROLE_USER'], $this->matrixOf(Feature::Support, false, 'ROLE_STUDENT'));

        $this->assertTrue($resolver->isEnabled(Feature::Support));
    }

    // all() answers the whole catalogue, keyed by the enum's own values - that shape is the mobile
    // app's `features` object and the nav's lookup table, so it is pinned here.
    public function testAllAnswersTheWholeCatalogue(): void
    {
        $all = $this->resolver(['ROLE_STUDENT'])->all();

        $this->assertCount(\count(Feature::cases()), $all);
        $this->assertArrayHasKey('student_work', $all);
        $this->assertArrayHasKey('school_mail', $all);
        $this->assertSame($all[Feature::SelfAssessment->value], $this->resolver(['ROLE_STUDENT'])->isEnabled(Feature::SelfAssessment));
    }

    // A feature switched on for nobody is off for a plain account - that is how §12.2 leaves the
    // unlinked-mail screen, the infrastructure and the activity history to the admins alone.
    public function testAFeatureOffOnEveryRoleIsAdminOnly(): void
    {
        $matrix = [];
        foreach (Feature::managedRoles() as $role) {
            $matrix[Feature::Infrastructure->value.'|'.$role] = false;
        }

        $this->assertFalse($this->resolver(['ROLE_STAFF'], $matrix)->isEnabled(Feature::Infrastructure));
        $this->assertTrue($this->resolver(['ROLE_ADMIN'], $matrix, isAdmin: true)->isEnabled(Feature::Infrastructure));
        // ...and still delegable to one person without touching an LDAP role.
        $delegated = $this->resolver(['ROLE_STAFF'], $matrix, [Feature::Infrastructure->value => FeatureAccessState::Enabled]);
        $this->assertTrue($delegated->isEnabled(Feature::Infrastructure));
    }
}
