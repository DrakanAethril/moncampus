<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\TeacherDossier\TeacherDossierPending;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// The shell's two questions about the dossiers intervenants of the person signed in: is there one
// to read (the profile menu's « Mes dossiers administratifs »), and is a piece expected of them
// (the home banner). Both answer « no » while the feature is unlit for that person - the service
// asks, so neither template has to remember to.
class TeacherDossierExtension extends AbstractExtension
{
    public function __construct(
        private readonly TeacherDossierPending $pending,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_teacher_dossiers', fn (): bool => $this->pending->hasDossiers($this->user())),
            new TwigFunction('teacher_dossier_awaited', fn (): ?array => $this->pending->awaited($this->user())),
        ];
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
