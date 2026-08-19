<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Everything App\Service\StudentAccountFactory needs to create one account, in primitives - so the
 * two screens that create accounts (Annuaire > Utilisateurs > Nouveau, and the class import) say
 * the same thing in the same words and cannot drift apart.
 */
final readonly class NewAccountRequest
{
    /**
     * @param string      $userType         one of App\Entity\LdapManageUser::USER_TYPES
     * @param string      $addedBy          the operator's own login, stamped on the queue row
     * @param list<string> $groups          secondary group CNs, joined by `|` on the queue row
     * @param bool        $directoryAccount whether an `account_create` row is queued at all, and
     *                                      with it the School mail addresses. False only for an
     *                                      account that has no Windows session to open and nothing
     *                                      to receive - a demonstration account of a test class.
     *                                      Turning it off also keeps the account out of Annuaire >
     *                                      Utilisateurs, which lists queue rows: a real decision,
     *                                      never a detail.
     */
    public function __construct(
        public string $firstname,
        public string $lastname,
        public string $userType,
        public string $addedBy,
        public array $groups = [],
        public ?string $contactEmail = null,
        public ?string $phoneNumber = null,
        public bool $mustChangePassword = true,
        public bool $testUser = false,
        public bool $directoryAccount = true,
    ) {
    }
}
