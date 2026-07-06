<?php

namespace App\Security;

use App\Entity\AbstractStructureNode;
use Symfony\Bundle\SecurityBundle\Security;

// Shared visibility rules for the Section > Année scolaire > Classe nav menu (see
// StructureNavigationExtension) and the placeholder pages it links to (ProgramController) -
// a plain service rather than logic duplicated in both places.
class StructureAccessChecker
{
    public function __construct(private readonly Security $security)
    {
    }

    public function isStaff(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_STAFF')
            || $this->security->isGranted('ROLE_STAFF-LEAD');
    }

    // A Section/Cohort node is visible to staff unconditionally, or to anyone holding the
    // role tied to that specific node's own linked LDAP group - a node with no linked group
    // is therefore staff-only.
    public function isNodeVisible(AbstractStructureNode $node): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        $ldapGroup = $node->getLdapGroup();

        return null !== $ldapGroup && $this->security->isGranted('ROLE_'.strtoupper($ldapGroup->getName()));
    }
}
