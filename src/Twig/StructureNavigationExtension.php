<?php

namespace App\Twig;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Repository\ProgramRepository;
use App\Repository\SectionRepository;
use App\Security\StructureAccessChecker;
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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('structure_nav_sections', $this->getSections(...)),
            new TwigFunction('structure_nav_school_year_groups', $this->getSchoolYearGroups(...)),
            new TwigFunction('is_structure_node_visible', $this->accessChecker->isNodeVisible(...)),
            new TwigFunction('is_staff', $this->accessChecker->isStaff(...)),
        ];
    }

    /** @return list<Section> */
    public function getSections(): array
    {
        return $this->sectionRepository->findActiveForNav();
    }

    // Only includes programs whose cohort is visible to the current user, and drops a school
    // year entirely once none of its programs are - avoids an orphan year header with nothing
    // underneath it for a user who can see the section but not any of its classes.
    /** @return list<array{schoolYear: SchoolYear, programs: list<Program>}> */
    public function getSchoolYearGroups(Section $section): array
    {
        $groups = [];

        foreach ($this->programGroupsBySection()[$section->getId()] ?? [] as $group) {
            $visiblePrograms = array_values(array_filter(
                $group['programs'],
                fn (Program $program): bool => $this->accessChecker->isNodeVisible($program->getCohort()),
            ));

            if ([] !== $visiblePrograms) {
                $groups[] = ['schoolYear' => $group['schoolYear'], 'programs' => $visiblePrograms];
            }
        }

        return $groups;
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
