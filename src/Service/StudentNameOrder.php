<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * The order a list of students is read in: surname, then first name, the French way (accents and
 * case do not move a name, « Élise » sits among the E). The UFA's lists all read through it, so that
 * a class list, a search's suggestions and a status table agree on who comes first.
 *
 * Lists sorted in SQL say the same thing with `ORDER BY st.lastname, st.firstname`; this is for
 * the ones built in PHP (a Program's roster, rows assembled per period).
 */
final class StudentNameOrder
{
    private static ?\Collator $collator = null;

    public static function compare(?User $a, ?User $b): int
    {
        $collator = self::$collator ??= new \Collator('fr_FR');

        foreach ([[$a?->getLastname(), $b?->getLastname()], [$a?->getFirstname(), $b?->getFirstname()], [$a?->getUsername(), $b?->getUsername()]] as [$left, $right]) {
            $order = (int) $collator->compare((string) $left, (string) $right);
            if (0 !== $order) {
                return $order;
            }
        }

        return ($a?->getId() ?? 0) <=> ($b?->getId() ?? 0);
    }

    /**
     * @template T
     *
     * @param array<array-key, T>  $items
     * @param callable(T): ?User   $student how to read the student of one item
     *
     * @return list<T>
     */
    public static function sort(array $items, callable $student): array
    {
        $items = array_values($items);
        usort($items, static fn (mixed $a, mixed $b): int => self::compare($student($a), $student($b)));

        return $items;
    }

    /**
     * @param array<array-key, User> $students
     *
     * @return list<User>
     */
    public static function sortStudents(array $students): array
    {
        return self::sort($students, static fn (User $student): User => $student);
    }
}
