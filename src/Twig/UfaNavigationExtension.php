<?php

namespace App\Twig;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the UFA nav's "Formations" submenu (templates/layout/app.html.twig) - one entry per
// active alternance Program (Modality::$isAlternance) for the current/most recent SchoolYear.
// Staff-only nav (same gate as the rest of the UFA dropdown), so unlike
// StructureNavigationExtension's per-viewer visibility filtering, every alternance Program for
// the year is shown here without a role check.
//
// Same layout as that Section menu: test Programs are pulled out of the list into a trailing
// "TEST ZONE" group instead of sitting among the real ones, where their "TEST - " display prefix
// (Program::getDisplayShortName()) made the alphabetical order look broken.
class UfaNavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly SchoolYearRepository $schoolYearRepository,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ufa_nav_formations', $this->getFormations(...)),
        ];
    }

    /**
     * Both lists sorted on the label the menu actually renders (displayShortName), case
     * insensitively, so it reads in the order it looks like it should - the repository's own
     * ORDER BY is on the raw shortName, which is not what the entries show.
     *
     * @return array{regular: list<Program>, test: list<Program>}
     */
    public function getFormations(): array
    {
        $schoolYear = $this->schoolYearRepository->findCurrentOrMostRecent();
        $user = $this->security->getUser();
        $viewer = $user instanceof User ? $user : null;

        $formations = null !== $schoolYear
            ? $this->programRepository->findAlternanceForSchoolYear($schoolYear, false, $viewer)
            : [];

        // Split out only for a real account, where test formations are the exception. A test
        // account sees nothing but test Programs (see findAlternanceForSchoolYear()), so pooling
        // them would empty the main list and leave the whole menu under a TEST ZONE header -
        // there they simply are the normal world. Same rule as StructureNavigationExtension.
        $poolTest = !($viewer?->isTestUser() ?? false);

        $groups = ['regular' => [], 'test' => []];
        foreach ($formations as $formation) {
            $groups[$poolTest && $formation->isTestProgram() ? 'test' : 'regular'][] = $formation;
        }

        foreach ($groups as &$group) {
            usort($group, static fn (Program $a, Program $b): int => strcasecmp($a->getDisplayShortName(), $b->getDisplayShortName()));
        }

        return $groups;
    }
}
