<?php

namespace App\Service;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Repository\EmailAliasRepository;
use App\Util\SchoolMailLocalPart;

/**
 * Checks a whole list of Courrier école addresses as an administration screen posted it - the
 * writing counterpart of App\Service\StudentMailAddressGenerator, which enforces the same rules
 * when it composes an address by itself.
 *
 * Gathered in a service rather than in App\Entity\EmailAlias's own constraints because two of the
 * three rules are not about one address: an address's validity depends on the other addresses in
 * the same submission (two identical lines) and on the addresses of every other student (reception
 * being catch-all, a local part taken is taken for the whole school). Neither is expressible on a
 * single entity, and the unique index alone would only surface as a database error at flush time,
 * with nothing to hang on the offending field.
 *
 * Only hand-typed addresses are examined: those derived from civil status or from the directory
 * follow their source and are never editable here (App\Enum\EmailAliasOrigin::isManageable()), so
 * re-judging them would only let a legacy row block an edit that has nothing to do with it. They
 * still count as occupants, of course - hence their presence in the duplicate scan below.
 */
class StudentMailAliasValidator
{
    public function __construct(private readonly EmailAliasRepository $aliasRepository)
    {
    }

    /**
     * @param array<string, EmailAlias> $aliases the submitted addresses, keyed by whatever key the
     *                                          caller wants the violations reported under (the form
     *                                          collection's row key, for the administration screen)
     *
     * @return array<string, array{message: string, parameters: array<string, string>}> at most one
     *                                                                                  violation per key, absent keys being valid
     */
    public function validate(User $user, array $aliases): array
    {
        $violations = [];

        // The first line holding a given local part keeps it; the next ones are the duplicates.
        // Locked addresses take part in this scan as occupants (typing one's own login address by
        // hand is a duplicate like any other), never as candidates.
        $seen = [];

        foreach ($aliases as $key => $alias) {
            $localPart = $alias->getLocalPart();

            if (!$alias->getOrigin()->isManageable()) {
                $seen[$localPart] ??= $key;

                continue;
            }

            $violation = $this->validateFormat($alias);

            if (null === $violation && isset($seen[$localPart])) {
                $violation = ['message' => 'emailAliasDuplicateLocalPartMessage', 'parameters' => []];
            }

            if (null === $violation) {
                $seen[$localPart] = $key;

                continue;
            }

            $violations[$key] = $violation;
        }

        return $violations + $this->findConflictsWithOtherStudents($user, array_diff_key($aliases, $violations));
    }

    /** @return array{message: string, parameters: array<string, string>}|null */
    private function validateFormat(EmailAlias $alias): ?array
    {
        $localPart = $alias->getLocalPart();

        if ('' === $localPart) {
            return ['message' => 'emailAliasBlankLocalPartMessage', 'parameters' => []];
        }

        if (SchoolMailLocalPart::isReserved($localPart)) {
            return ['message' => 'emailAliasReservedLocalPart', 'parameters' => []];
        }

        if (!SchoolMailLocalPart::isWellFormed($localPart)) {
            return ['message' => 'emailAliasMalformedLocalPart', 'parameters' => []];
        }

        if ($alias->getOrigin()->requiresDot() && !SchoolMailLocalPart::hasRequiredDot($localPart)) {
            return ['message' => 'emailAliasMissingDot', 'parameters' => []];
        }

        return null;
    }

    /**
     * @param array<string, EmailAlias> $aliases
     *
     * @return array<string, array{message: string, parameters: array<string, string>}>
     */
    private function findConflictsWithOtherStudents(User $user, array $aliases): array
    {
        $candidates = array_filter($aliases, static fn (EmailAlias $alias): bool => $alias->getOrigin()->isManageable() && '' !== $alias->getLocalPart());

        if ([] === $candidates) {
            return [];
        }

        $owners = [];
        foreach ($this->aliasRepository->findByLocalParts(array_map(static fn (EmailAlias $alias): string => $alias->getLocalPart(), $candidates)) as $existing) {
            // The student's own rows are in the submitted list already - a row colliding with
            // itself is not a collision.
            if ($existing->getUser() !== $user) {
                $owners[$existing->getLocalPart()] = $existing->getUser();
            }
        }

        $violations = [];
        foreach ($candidates as $key => $alias) {
            $owner = $owners[$alias->getLocalPart()] ?? null;

            if (null !== $owner) {
                $violations[$key] = [
                    'message' => 'emailAliasLocalPartTakenMessage',
                    // The owner is named rather than just signalled: without it, staff faced with a
                    // refusal have no way of telling a namesake apart from a typo.
                    'parameters' => ['%student%' => trim($owner->getFirstname().' '.$owner->getLastname()) ?: $owner->getUsername()],
                ];
            }
        }

        return $violations;
    }
}
