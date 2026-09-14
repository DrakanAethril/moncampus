<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\VisibilityLevel;
use App\Security\ProgramTimetableAccess;
use App\Service\Jobboard\JobboardPerimeter;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Small standalone extension for the per-nav-entry visibility fields (Program::$timetableVisibility/
// $syllabusVisibility/$alternanceCalendarVisibility), used in templates/layout/app.html.twig.
// Deliberately separate from Program::$visibility's own filtering in
// ProgramRepository::findActiveForNav()/findAllForTeacher() - different call sites (Twig nav
// rendering vs. PHP picker queries), same underlying VisibilityLevel::allowsRoles() logic.
class VisibilityExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly ProgramTimetableAccess $timetableAccess,
        private readonly JobboardPerimeter $jobboardPerimeter,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('visibility_allows', $this->allows(...)),
            // The whole rule for one nav entry rather than its tier alone: feature + management
            // flag + tier, from the same service the screens and the feeds ask. The three were
            // spelled out inline in three templates, and the one that forgot the tier is how a
            // formation reserved to the administration ended up on its students' bar.
            new TwigFunction('timetable_visible', $this->timetableAccess->isVisible(...)),
            // The Jobboard's own nav rule, and the reason it is not a tier read off one Program:
            // the entry opens a board covering every filière the reader is in, so what decides it
            // is the perimeter itself - « au moins une formation m'ouvre son jobboard ». The
            // perimeter memoises per request, so the nav asking costs the query the screen was
            // going to make anyway.
            new TwigFunction('jobboard_visible', $this->jobboardVisible(...)),
        ];
    }

    public function jobboardVisible(): bool
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User && $this->jobboardPerimeter->isVisible($viewer);
    }

    public function allows(VisibilityLevel $level): bool
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User && $level->allowsRoles($viewer->getRoles());
    }
}
