<?php

namespace App\Service;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a student their School mail addresses: the readable "firstname.lastname" one (primary, the
 * one displayed and written from) and their LDAP login as a secondary alias, so both forms deliver.
 *
 * Called both by the backfill of existing students (App\Command\BackfillStudentMailAliasesCommand)
 * and by account creation (App\Controller\DirectoryUserController::new()). Idempotent: a student
 * already provisioned is left alone, since an address that already reached a company must never be
 * regenerated.
 */
class StudentMailProvisioner
{
    public function __construct(
        private readonly StudentMailAddressGenerator $addressGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<EmailAlias> the aliases created by this call - empty if the student already had some
     */
    public function provisionFor(User $user): array
    {
        if (null !== $user->getPrimaryAlias()) {
            return [];
        }

        $created = [];

        $primary = (new EmailAlias())
            ->setLocalPart($this->addressGenerator->generateFor($user))
            ->setOrigin(EmailAliasOrigin::Generated);

        // Attached through the student rather than by setting the owning side alone, so the
        // in-memory User is not left disagreeing with the database - App\Form\UserProfileType's
        // collection reads User::getEmailAliases(), and account creation now provisions and renders
        // within the same request.
        $user->addEmailAlias($primary);
        $this->entityManager->persist($primary);

        // Doctrine inserts the alias before filling this pointer in: the join being nullable, it
        // sait rompre le cycle User → EmailAlias → User au moment de calculer l'ordre d'insertion.
        $user->setPrimaryAlias($primary);
        $created[] = $primary;

        // The login alias is only added when it is free: if a namesake already took it, we do
        // without rather than fail - it is a convenience, not the reference address.
        $loginLocalPart = $this->addressGenerator->normalizeLoginAlias($user->getUsername());

        if ('' !== $loginLocalPart
            && $loginLocalPart !== $primary->getLocalPart()
            && $this->addressGenerator->isAvailable($loginLocalPart)
        ) {
            $this->addressGenerator->reserve($loginLocalPart);

            $secondary = (new EmailAlias())
                ->setLocalPart($loginLocalPart)
                // The only origin without a dot, and the only one exempt from the rule: it is not
                // typed but taken from the directory, and no screen administers it.
                ->setOrigin(EmailAliasOrigin::Login);

            $user->addEmailAlias($secondary);
            $this->entityManager->persist($secondary);
            $created[] = $secondary;
        }

        return $created;
    }
}
