<?php

namespace App\Twig;

use App\Entity\User;
use App\Enum\VisibilityLevel;
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
    public function __construct(private readonly Security $security)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('visibility_allows', $this->allows(...)),
        ];
    }

    public function allows(VisibilityLevel $level): bool
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User && $level->allowsRoles($viewer->getRoles());
    }
}
