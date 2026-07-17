<?php

namespace App\Twig;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Repository\ProgramRepository;
use App\Repository\SectionRepository;
use App\Security\StructureAccessChecker;
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
    /** @var array<int, array<int, array{schoolYear: SchoolYear, programs: list<Program>}>>|null */
    private ?array $programGroupsBySection = null;

    public function __construct(
        private readonly SectionRepository $sectionRepository,
        private readonly ProgramRepository $programRepository,
        private readonly StructureAccessChecker $accessChecker,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('structure_nav_sections', $this->getSections(...)),
            new TwigFunction('structure_nav_school_year_groups', $this->getSchoolYearGroups(...)),
            new TwigFunction('structure_nav_current_program_section_id', $this->getCurrentProgramSectionId(...)),
            new TwigFunction('structure_nav_current_test_program', $this->getCurrentTestProgram(...)),
            new TwigFunction('is_staff', $this->accessChecker->isStaff(...)),
            new TwigFunction('is_program_teacher', $this->accessChecker->isProgramTeacher(...)),
        ];
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
    // they're actually linked to.
    /** @return list<array{schoolYear: SchoolYear, programs: list<Program>}> */
    public function getSchoolYearGroups(Section $section): array
    {
        $groups = [];

        foreach ($this->programGroupsBySection()[$section->getId()] ?? [] as $group) {
            $visiblePrograms = array_values(array_filter(
                $group['programs'],
                fn (Program $program): bool => $this->accessChecker->isProgramVisible($program),
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

        if (null === $request || !str_starts_with((string) $request->attributes->get('_route'), 'app_program_')) {
            return null;
        }

        $programId = $request->attributes->get('id');

        if (null === $programId) {
            return null;
        }

        return $this->programRepository->find($programId);
    }

    /** @return array<int, array<int, array{schoolYear: SchoolYear, programs: list<Program>}>> */
    private function programGroupsBySection(): array
    {
        if (null !== $this->programGroupsBySection) {
            return $this->programGroupsBySection;
        }

        $grouped = [];
        foreach ($this->programRepository->findActiveForNav() as $program) {
            $sectionId = $program->getCohort()->getTrack()->getSection()->getId();
            $schoolYearId = $program->getSchoolYear()->getId();

            $grouped[$sectionId][$schoolYearId]['schoolYear'] ??= $program->getSchoolYear();
            $grouped[$sectionId][$schoolYearId]['programs'][] = $program;
        }

        return $this->programGroupsBySection = $grouped;
    }

    public function reset(): void
    {
        $this->programGroupsBySection = null;
    }
}
