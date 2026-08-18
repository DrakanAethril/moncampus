<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageUser;
use App\Entity\User;

/**
 * What App\Service\StudentAccountFactory just built and persisted - nothing is flushed, the caller
 * owns the transaction.
 */
final readonly class CreatedAccount
{
    /**
     * @param LdapManageUser|null $directoryRequest  the queued account_create row, null when the
     *                                               account is deliberately kept out of the directory
     * @param bool                $schoolMailFailed  a civil status that transliterates to nothing (or
     *                                               a hundredth namesake) leaves the student without
     *                                               an address; the account is still created, and the
     *                                               caller is the one that has to say so
     */
    public function __construct(
        public User $user,
        public ?LdapManageUser $directoryRequest,
        public bool $schoolMailFailed = false,
    ) {
    }

    public function login(): string
    {
        return $this->user->getUsername();
    }
}
