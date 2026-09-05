<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * How a class's people are ordered and named **on a document**, which is not how the two list
 * screens draw them.
 *
 * The screens sort on the display name, which starts with the first name: a wall of cards is read
 * by looking, not by scanning a column. A printed émargement sheet and an exported file are read
 * the other way round - somebody looks for a surname down a column, and a roll ordered by first
 * name is unusable for that. So both exports sort on the surname, and the sheet prints it first.
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
