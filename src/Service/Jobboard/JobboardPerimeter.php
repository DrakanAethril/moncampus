<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionRepository;

/**
 * Which filières one person reads. **The single answer**, and it lands in a WHERE clause rather
 * than in a template: a student must not be able to pull an offer from another filière, even if
 * the screen would have hidden it.
 *
 * | | filières |
 * |---|---|
 * | administrateur, personnel | toutes |
 * | enseignant | les sections des formations où il enseigne |
 * | étudiant | les sections des formations où il est inscrit |
 * | tuteur, externe, e-CO | aucune |
 *
 * An empty perimeter is a legitimate answer and gives an empty screen - never a 404, which would
 * say the feature does not exist while it is perfectly well lit.
 *
 * Deliberately memoizes nothing: a service that remembers across requests answers the next one
 * with the previous reader's perimeter, the FrankenPHP container outliving the request.
 */
final readonly class JobboardPerimeter
{
    /** The roles that read every filière - the same three App\Security\StructureAccessChecker calls staff. */
    private const array WIDE_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function __construct(private SectionRepository $sections)
    {
    }

    /** @return list<Section> */
    public function sections(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        if ([] !== array_intersect(self::WIDE_ROLES, $user->getRoles())) {
            return $this->sections->findAllOrdered();
        }

        return $this->sections->findForMember($user);
    }

    /**
     * Does this person read more than one filière? That - and not a role - is what decides whether
     * the « Filières » filter is drawn: a selector with one entry is not a choice.
     */
    public function offersAChoice(?User $user): bool
    {
        return \count($this->sections($user)) > 1;
    }
}
