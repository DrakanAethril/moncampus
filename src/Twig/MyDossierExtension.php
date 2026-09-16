<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\DossierRepository;
use App\Service\Dossier\DossierTargetResolver;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the « Dossiers documentaires » entry of the Ressources menu, which must not be offered to
// a student no dossier is addressed to: the feature can be lit for a whole role while only one
// class is actually being asked for anything, and an entry opening on « aucun dossier » is an entry
// they learn to ignore - the same posture as « Mes machines virtuelles » and the shared wikis.
//
// The question is the one MyDossierController::index() answers before drawing a row: published,
// naming them, and - once the target is narrowed to an option - carrying that option. Asked from
// here rather than passed by a controller because the navigation is rendered on every authenticated
// page and no controller owns it.
//
// Memoized for the request and dropped between requests: under FrankenPHP worker mode a Twig
// extension is a singleton that outlives the request, and a memo with no reset would answer the
// first visitor's menu to everybody else in the same worker.
class MyDossierExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $hasDossiers = null;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierTargetResolver $targets,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_dossiers', $this->hasDossiers(...)),
        ];
    }

    public function hasDossiers(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        return $this->hasDossiers ??= $this->compute($user);
    }

    #[\Override]
    public function reset(): void
    {
        $this->hasDossiers = null;
    }

    private function compute(User $user): bool
    {
        foreach ($this->dossiers->findPublishedForStudent($user) as $dossier) {
            if ($this->targets->isTarget($dossier, $user)) {
                return true;
            }
        }

        return false;
    }
}
