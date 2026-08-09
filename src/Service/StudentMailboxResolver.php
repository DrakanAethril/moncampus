<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\EmailAliasRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The address a student writes from in School mail (`firstname.lastname@etu.beaupeyrat.org`,
 * `@devetu...` outside production).
 *
 * Shared by the mailbox (screen 3b, identity banner) and the compose screen (screen 3d, "From"
 * row): both must name exactly the same address, otherwise the identity banner - which exists
 * precisely so the two mailboxes are never confused - would be lying.
 */
class StudentMailboxResolver
{
    public function __construct(
        private readonly EmailAliasRepository $aliasRepository,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        private readonly string $studentMailDomain,
    ) {
    }

    public function addressFor(User $student): ?string
    {
        $primary = $student->getPrimaryAlias();

        if (null !== $primary && $primary->isActive()) {
            return $primary->toAddress($this->studentMailDomain);
        }

        foreach ($this->aliasRepository->findAllForUser($student) as $alias) {
            if ($alias->isActive()) {
                return $alias->toAddress($this->studentMailDomain);
            }
        }

        return null;
    }
}
