<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\EmailAliasRepository;
use App\Util\SchoolMailLocalPart;

/**
 * Builds the "firstname.lastname" local part of a student's School mail address.
 *
 * Distinct from App\Service\LoginGenerator, which produces the LDAP identifier (`croux`): that one
 * has to stay short and aligned with the directory, whereas an address printed on a CV and read by
 * a recruiter has to be legible. The two coexist - the login stays registered as a secondary alias,
 * so both addresses deliver.
 *
 * A pleasant side effect of the longer form: it collides *less* often than the login. `croux`
 * catches Camille, Clement and Chloe Roux; `camille.roux` only clashes with a full namesake, which
 * is rare at the scale of one school.
 *
 * Transliteration rules, frozen once and for all (an address that reached a company is not
 * changed):
 * - ASCII fallback and lowercase: `Chloe` -> `chloe`;
 * - hyphens kept: `Jean-Pierre` -> `jean-pierre`;
 * - in the last name, spaces removed, which glues particles: `Le Gall` -> `legall`;
 * - in the first name, we stop at the first space: `Mouhamadoun Aly` -> `mouhamadoun`;
 * - apostrophes removed: `d'Arcy` -> `darcy`;
 * - real namesakes separated by a numeric suffix starting at 2.
 */
class StudentMailAddressGenerator
{
    /** Local-part limit of a mail address, RFC 5321. */
    private const int MAX_LOCAL_PART_LENGTH = 64;

    /** Safety bound: beyond it, failing loudly beats iterating forever. */
    private const int MAX_COLLISION_ATTEMPTS = 99;

    /**
     * Local parts already handed out by this instance, on top of those present in the database.
     *
     * Needed because the uniqueness check is a query: two namesakes processed in the same batch,
     * before the flush, would both be judged free and would collide on the unique constraint. It
     * matters for batch backfills as much as for `--dry-run`, which writes nothing at all and would
     * therefore never find anything in the database.
     *
     * @var array<string, true>
     */
    private array $issued = [];

    public function __construct(private readonly EmailAliasRepository $aliasRepository)
    {
    }

    /**
     * @throws \RuntimeException when the student has neither a usable first nor last name, or when
     *                          every numbered variant is already taken
     */
    public function generateFor(User $user): string
    {
        $base = $this->buildBase($user->getFirstname(), $user->getLastname());

        if ('' === $base) {
            throw new \RuntimeException(sprintf(
                'Impossible de composer une adresse pour l\'utilisateur "%s" : prénom et nom vides après translittération.',
                $user->getUsername(),
            ));
        }

        if ($this->isAvailable($base)) {
            return $this->issue($base);
        }

        for ($suffix = 2; $suffix <= self::MAX_COLLISION_ATTEMPTS; ++$suffix) {
            // The suffix is added *after* truncation, so that a very long name does not overflow
            // the limit as it gets numbered.
            $candidate = $this->truncate($base, self::MAX_LOCAL_PART_LENGTH - \strlen((string) $suffix)).$suffix;

            if ($this->isAvailable($candidate)) {
                return $this->issue($candidate);
            }
        }

        throw new \RuntimeException(sprintf('Aucune adresse libre pour la base "%s".', $base));
    }

    /** The normalised form of an existing login, to register it as a secondary alias. */
    public function normalizeLoginAlias(string $login): string
    {
        return $this->truncate($this->clean($login), self::MAX_LOCAL_PART_LENGTH);
    }

    /** Reserves a local part decided outside the generator (the login alias, for instance). */
    public function reserve(string $localPart): void
    {
        $this->issued[$localPart] = true;
    }

    /**
     * Available *and* admissible: the reserved list is consulted here rather than in generation
     * alone, so that a future manual alias creation hits the same barrier.
     */
    public function isAvailable(string $localPart): bool
    {
        if (SchoolMailLocalPart::isReserved($localPart)) {
            return false;
        }

        return !isset($this->issued[$localPart]) && !$this->aliasRepository->localPartExists($localPart);
    }

    private function issue(string $localPart): string
    {
        $this->issued[$localPart] = true;

        return $localPart;
    }

    private function buildBase(?string $firstname, ?string $lastname): string
    {
        $first = $this->clean($this->firstGivenName($firstname ?? ''));
        $last = $this->clean($lastname ?? '');

        // A student with only one of the two halves filled in still gets a usable address, rather
        // than an orphan dot at the front or the back.
        $base = match (true) {
            '' !== $first && '' !== $last => $first.'.'.$last,
            '' !== $last => $last,
            default => $first,
        };

        return $this->truncate($base, self::MAX_LOCAL_PART_LENGTH);
    }

    /**
     * Keeps only the first given name of a civil status carrying several.
     *
     * Asymmetric with the last name, and deliberately so: a space in a last name separates a
     * particle from its name (`Le Gall`, `El Hani`), which form an indivisible whole and are
     * therefore glued; a space in a first name separates successive given names, of which only the
     * first is the one in use. Gluing them would give `mouhamadounaly.waigalo` instead of
     * `mouhamadoun.waigalo`.
     *
     * The hyphen, on the other hand, stays: `Jean-Pierre` is one compound first name, not two.
     */
    private function firstGivenName(string $firstname): string
    {
        $tokens = preg_split('/\s+/u', trim($firstname), 2) ?: [];

        return $tokens[0] ?? '';
    }

    private function clean(string $part): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $part);
        $lowered = mb_strtolower(false !== $ascii ? $ascii : $part);

        // Anything that is neither a letter, a digit nor a hyphen disappears: spaces (which glue
        // particles) and apostrophes fall here, with no special handling.
        $clean = preg_replace('/[^a-z0-9-]/', '', $lowered) ?? '';

        // `Jean--Pierre` or a hyphen at either end would produce a malformed address.
        $clean = preg_replace('/-+/', '-', $clean) ?? '';

        return trim($clean, '-');
    }

    /** Truncates without ever leaving a separator at the end of the address. */
    private function truncate(string $value, int $maxLength): string
    {
        if (\strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(substr($value, 0, $maxLength), '-.');
    }
}
