<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\VisibilityLevel;
use App\Security\ProgramTimetableAccess;
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
        ];
    }

    public function allows(VisibilityLevel $level): bool
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User && $level->allowsRoles($viewer->getRoles());
    }
}
