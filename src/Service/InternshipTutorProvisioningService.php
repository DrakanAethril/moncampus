<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Repository\InternshipTutorLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves platform access for a tutor named on an InternshipTutorLink, without ever blocking the
 * staff member's request on the LDAP account actually existing (see App\Entity\LdapManageUser for
 * the async queue this hands off to - inserting a row here is a plain, instant DB write, never a
 * wait on the separate consumer script that actually provisions the account).
 *
 * Distinguishes three cases purely by whether this tutorEmail already appears on another link:
 *  - a resolved tutor (already JIT-linked from a previous internship, see
 *    InternshipTutorEvaluationController::home()) is reused directly, no queueing needed;
 *  - a still-pending request (queued but the tutor hasn't logged in yet) is reused rather than
 *    asking the consumer script to create a second account for the same person;
 *  - otherwise a brand new account_create row is queued, and this link keeps a reference to it so
 *    the eventual login can be matched by the login the consumer script generates (see
 *    InternshipTutorLinkRepository::findActiveForTutorUser()).
 */
class InternshipTutorProvisioningService
{
    public function __construct(
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function provision(InternshipTutorLink $tutorLink, User $requestedBy): void
    {
        $existing = $this->tutorLinkRepository->findOneMostRecentByTutorEmail($tutorLink->getTutorEmail(), $tutorLink);

        if (null !== $existing?->getTutor()) {
            $tutorLink->setTutor($existing->getTutor());

            return;
        }

        if (null !== $existing?->getLdapManageUser()) {
            $tutorLink->setLdapManageUser($existing->getLdapManageUser());

            return;
        }

        $ldapUser = new LdapManageUser($tutorLink->getTutorFirstName(), $tutorLink->getTutorLastName(), 'external', 'account_create');
        $ldapUser->setAddedBy($requestedBy->getUsername());

        $this->entityManager->persist($ldapUser);
        $tutorLink->setLdapManageUser($ldapUser);
    }
}
