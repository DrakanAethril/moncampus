<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\SectionRepository;
use App\Security\FeatureAccess;
use App\Security\StructureAccessChecker;
use App\Service\StudentAlternanceProgramResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the Section > Année scolaire > Classe nav menu rendered in
// templates/layout/app.html.twig - a Twig extension (rather than passing this data from
// every controller) since the navbar is shared across every authenticated page.
//
// Implements ResetInterface because this service is a singleton that outlives a single request
// under FrankenPHP worker mode: without resetting $programGroupsBySection between requests, the
// first request to compute it would keep serving that same stale grouping to every later request
// in the same worker, hiding any Program added after the worker booted.
class StructureNavigationExtension extends AbstractExtension implements ResetInterface
{
    // Pools every test Program (Program::$testProgram) into a single "TEST ZONE" group per
    // section, regardless of their real school year, always shown last - see
    // programGroupsBySection(). An int-keyed sibling array wouldn't risk colliding with a real
    // SchoolYear id, but a string key reads clearer at the call sites below.
    private const TEST_ZONE_KEY = 'test-zone';

    /** @var array<int, array<int|string, array{schoolYear: ?SchoolYear, programs: list<Program>}>>|null */
    private ?array $programGroupsBySection = null;

    // Presence-based nav gate for the "Quiz" entry (design/design_campus_manager/README.md's
    // "Générateur de quiz" section: "Si au moins une instance de quizz est associée à un
    // programme, un lien Quizz apparaît...") - deliberately not a Program::$xxxManagementEnabled
    // flag like the other nav entries, since this is about whether there's anything to show, not
    // a feature toggle. Fetched once per request as a single DISTINCT query (see
    // QuizInstanceRepository::findProgramIdsWithInstances()), not one COUNT per Program row - this
    // nav renders on every authenticated page for every visible Program.
    /** @var array<int, true>|null */
    private ?array $programIdsWithQuizInstances = null;

    /** @var list<Program>|null */
    private ?array $studentPrograms = null;

    public function __construct(
        private readonly SectionRepository $sectionRepository,
        private readonly ProgramRepository $programRepository,
        private readonly QuizInstanceRepository $quizInstanceRepository,
        private readonly StructureAccessChecker $accessChecker,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly StudentAlternanceProgramResolver $alternanceProgramResolver,
        private readonly FeatureAccess $featureAccess,
        private readonly VisibilityExtension $visibility,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('structure_nav_sections', $this->getSections(...)),
            new TwigFunction('structure_nav_school_year_groups', $this->getSchoolYearGroups(...)),
            new TwigFunction('structure_nav_current_program_section_id', $this->getCurrentProgramSectionId(...)),
            new TwigFunction('structure_nav_current_test_program', $this->getCurrentTestProgram(...)),
            new TwigFunction('is_staff', $this->accessChecker->isStaff(...)),
            new TwigFunction('is_program_teacher', $this->accessChecker->isProgramTeacher(...)),
            new TwigFunction('program_has_quiz_instances', $this->hasQuizInstances(...)),
            new TwigFunction('program_nav_has_entries', $this->hasNavEntries(...)),
            new TwigFunction('student_nav_programs', $this->getStudentPrograms(...)),
            new TwigFunction('student_nav_alternance_program', $this->getStudentAlternanceProgram(...)),
        ];
    }

    // Powers the student navbar (design_handoff_dashboards §3): the "Emploi du temps" tab needs
    // the student's active Program(s), and "Mon alternance" only shows for an alternant. Cached
    // per request like $programGroupsBySection - the navbar renders on every page.
    /** @return list<Program> */
    public function getStudentPrograms(): array
    {
        if (null !== $this->studentPrograms) {
            return $this->studentPrograms;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->security->isGranted('ROLE_STUDENT')) {
            return $this->studentPrograms = [];
        }

        return $this->studentPrograms = $this->programRepository->findAllActiveForStudent($user);
    }

    // "Mon alternance" shows only for a student actually tagged with their class's alternance
    // modality - see App\Service\StudentAlternanceProgramResolver, shared with the page the tab
    // opens so the two can never disagree about who is an alternant.
    public function getStudentAlternanceProgram(): ?Program
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->security->isGranted('ROLE_STUDENT')) {
            return null;
        }

        return $this->alternanceProgramResolver->resolve($user);
    }

    /**
     * Whether a class's submenu holds at least one entry for the person reading it - the mirror of
     * what templates/layout/app.html.twig renders inside the class dropend.
     *
     * It has to be kept in step with that template by hand, which is why
     * tests/Functional/NavigationEmptyMenusTest.php walks the rendered bar for every role and
     * refuses any panel without a link: the drift shows there rather than on somebody's screen.
     */
    public function hasNavEntries(Program $program): bool
    {
        // An administrator always reads the two lists, the syllabus and the sequences; a staff
        // member always reads the « Paramétrage » submenu. Neither can be empty, so neither needs
        // the rest of this method.
        if ($this->security->isGranted('ROLE_ADMIN') || $this->accessChecker->isStaff()) {
            return true;
        }

        if ($this->featureAccess->isEnabled(Feature::Timetable)
            && $program->isTimetableManagementEnabled()
            && $this->visibility->allows($program->getTimetableVisibility())) {
            return true;
        }

        if ($this->featureAccess->isEnabled(Feature::MyAlternance)
            && $this->visibility->allows($program->getAlternanceCalendarVisibility())) {
            return true;
        }

        if (!$this->security->isGranted('ROLE_STUDENT')) {
            return false;
        }

        return ($this->featureAccess->isEnabled(Feature::UfaBooklet) && $program->isInternshipManagementEnabled())
            || ($this->featureAccess->isEnabled(Feature::StudentWork) && $program->isAssignmentManagementEnabled())
            || ($this->featureAccess->isEnabled(Feature::QuizTake) && $this->hasQuizInstances($program));
    }

    public function hasQuizInstances(Program $program): bool
    {
        if (null === $this->programIdsWithQuizInstances) {
            $this->programIdsWithQuizInstances = array_fill_keys($this->quizInstanceRepository->findProgramIdsWithInstances(), true);
        }

        return isset($this->programIdsWithQuizInstances[$program->getId()]);
    }

    /** @return list<Section> */
    public function getSections(): array
    {
        return $this->sectionRepository->findActiveForNav();
    }

    // Only includes programs the current user can actually access (see
    // StructureAccessChecker::isProgramVisible()), and drops a school year entirely once none of
    // its programs are - avoids an orphan year header with nothing underneath it. The template
    // uses this same result to also decide whether to show the Section header at all, so a
    // student/teacher's own Section only ever appears when it leads to at least one Program
    // they're actually linked to. A null `schoolYear` marks the trailing "TEST ZONE" group (see
    // programGroupsBySection()) - the template renders that header differently.
    /** @return list<array{schoolYear: ?SchoolYear, programs: list<Program>}> */
    public function getSchoolYearGroups(Section $section): array
    {
        $groups = [];

        foreach ($this->programGroupsBySection()[$section->getId()] ?? [] as $group) {
            // Two questions, and both have to be answered here rather than in the template: may
            // this person see the class at all, and does its submenu lead anywhere. A class whose
            // every entry is switched off would otherwise draw a name that opens on an empty panel,
            // and dropping it here is what also empties its school-year group and, in turn, its
            // Section - the template reads this same result to decide whether to draw either.
            $visiblePrograms = array_values(array_filter(
                $group['programs'],
                fn (Program $program): bool => $this->accessChecker->isProgramVisible($program) && $this->hasNavEntries($program),
            ));

            if ([] !== $visiblePrograms) {
                $groups[] = ['schoolYear' => $group['schoolYear'], 'programs' => $visiblePrograms];
            }
        }

        return $groups;
    }

    // Every Program-scoped route (app_program_*) carries the Program's id as its {id} route
    // parameter - used to highlight the Section/Program dropdown levels themselves, which
    // otherwise have no active-state check of their own (unlike the individual links inside
    // them, each checked against its own exact route name in the template).
    public function getCurrentProgramSectionId(): ?int
    {
        return $this->getCurrentProgram()?->getCohort()->getTrack()->getSection()->getId();
    }

    // Powers the "test program" warning banner in templates/layout/app.html.twig - resolved from
    // the route itself (not from a 'program' template variable) so the banner appears on every
    // app_program_* page regardless of what each controller happens to name its render() context.
    public function getCurrentTestProgram(): ?Program
    {
        $program = $this->getCurrentProgram();

        return $program?->isTestProgram() ? $program : null;
    }

    private function getCurrentProgram(): ?Program
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !str_starts_with($request->attributes->getString('_route'), 'app_program_')) {
            return null;
        }

        $programId = $request->attributes->get('id');

        if (null === $programId) {
            return null;
        }

        return $this->programRepository->find($programId);
    }

    /** @return array<int, array<int|string, array{schoolYear: ?SchoolYear, programs: list<Program>}>> */
    private function programGroupsBySection(): array
    {
        if (null !== $this->programGroupsBySection) {
            return $this->programGroupsBySection;
        }

        $viewer = $this->security->getUser();

        if (!$viewer instanceof User) {
            return $this->programGroupsBySection = [];
        }

        $grouped = [];
        foreach ($this->programRepository->findActiveForNav($viewer) as $program) {
            $sectionId = $program->getCohort()->getTrack()->getSection()->getId();

            // Pooled into TEST ZONE only for a real account, where they are the exception. For a
            // test account every Program it can see is a test one, so pooling them all would
            // collapse the whole nav into a single group and lose the school-year split - there
            // they are simply the normal world and group like any other.
            if ($program->isTestProgram() && !$viewer->isTestUser()) {
                $grouped[$sectionId][self::TEST_ZONE_KEY]['schoolYear'] ??= null;
                $grouped[$sectionId][self::TEST_ZONE_KEY]['programs'][] = $program;
                continue;
            }

            $schoolYearId = $program->getSchoolYear()->getId();
            $grouped[$sectionId][$schoolYearId]['schoolYear'] ??= $program->getSchoolYear();
            $grouped[$sectionId][$schoolYearId]['programs'][] = $program;
        }

        // The query orders programs by their real school year, so a section's test programs -
        // pooled above regardless of which real school year each actually belongs to - can end
        // up interleaved out of alphabetical order, and the TEST ZONE key can land anywhere
        // among the real school years instead of always last. Fix both: re-sort the pooled
        // programs, then drop and re-append the key so it's always the final group (PHP arrays
        // preserve insertion order).
        foreach ($grouped as $sectionId => $yearGroups) {
            if (!isset($yearGroups[self::TEST_ZONE_KEY])) {
                continue;
            }

            $testZone = $yearGroups[self::TEST_ZONE_KEY];
            usort($testZone['programs'], static fn (Program $a, Program $b): int => $a->getShortName() <=> $b->getShortName());

            unset($grouped[$sectionId][self::TEST_ZONE_KEY]);
            $grouped[$sectionId][self::TEST_ZONE_KEY] = $testZone;
        }

        return $this->programGroupsBySection = $grouped;
    }

    public function reset(): void
    {
        $this->programGroupsBySection = null;
        $this->programIdsWithQuizInstances = null;
        $this->studentPrograms = null;
    }
}
