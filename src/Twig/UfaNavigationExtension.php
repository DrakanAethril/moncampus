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
class UfaNavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly SchoolYearRepository $schoolYearRepository,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ufa_nav_formations', $this->getFormations(...)),
        ];
    }

    /** @return list<Program> */
    public function getFormations(): array
    {
        $schoolYear = $this->schoolYearRepository->findCurrentOrMostRecent();
        $viewer = $this->security->getUser();

        return null !== $schoolYear
            ? $this->programRepository->findAlternanceForSchoolYear($schoolYear, false, $viewer instanceof User ? $viewer : null)
            : [];
    }
}
