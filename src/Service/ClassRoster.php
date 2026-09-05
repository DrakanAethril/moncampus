<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * How a class's people are ordered, and how they are named on a document.
 *
 * **The surname decides the order**, everywhere the same people are listed: the two class lists
 * (App\Controller\ProgramController), the émargement sheet and the CSV exports. They used to sort
 * on the display name, which starts with the first name - so « Zoé Aubert » came after « Anne
 * Zambelli », and the printed roll and the screen it was printed from disagreed about who came
 * first. One rule, one class.
 *
 * Naming is the half that still differs, deliberately: a card shows « Zoé Aubert », a signed
 * document leads with « AUBERT Zoé », which is the spelling somebody scans a column for.
 *
 * The fallback is the display name kept whole, then the login: an account LDAP never named
 * (neither `sn` nor `givenName`) must still occupy its own line rather than an empty one.
 */
class ClassRoster
{
    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    public function ordered(array $users): array
    {
        usort($users, fn (User $a, User $b): int => [$this->fold($this->surname($a)), $this->fold($this->given($a))]
            <=> [$this->fold($this->surname($b)), $this->fold($this->given($b))]);

        return $users;
    }

    /** The surname in caps, then the first name - the spelling an official document is signed under. */
    public function documentName(User $user): string
    {
        $given = $this->given($user);
        $surname = $this->surname($user);

        return '' !== $given ? mb_strtoupper($surname).' '.$given : $surname;
    }

    /** The `nom` column of an export, and the head of a printed line. */
    public function surname(User $user): string
    {
        $lastname = trim($user->getLastname() ?? '');

        return '' !== $lastname ? $lastname : trim($user->getDisplayName() ?? $user->getUsername());
    }

    /** Empty whenever the surname above had to fall back, so that a name is never cut in two. */
    public function given(User $user): string
    {
        return '' !== trim($user->getLastname() ?? '') ? trim($user->getFirstname() ?? '') : '';
    }

    // Accents and case dropped, so « Élodie » sorts where a reader expects it rather than after Z.
    private function fold(string $value): string
    {
        return AsciiFolder::fold($value);
    }
}
