<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardOffer;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Reading the board: the perimeter, the filters, the order and the cursor - in one place, because
 * the screen and its « Afficher 40 offres de plus » are two doors onto the same list and must not
 * be able to answer differently.
 *
 * Two things are not negotiable here:
 *
 * - **the perimeter is a WHERE clause**, applied before anything else and never removable by a
 *   filter. A filière ticked outside the reader's own is intersected away, not refused: the answer
 *   to a forged query string is "nothing", not an error page explaining what exists;
 * - **the order is (sort_date DESC, id DESC)** and the cursor is that exact pair. `sortDate` is
 *   why: an offer with no publication date sorts under year 1000 rather than under a NULL, and a
 *   NULL in an ORDER BY is not a stable place.
 */
final readonly class JobboardOfferFinder
{
    public const int PAGE_SIZE = 40;

    public function __construct(
        private JobboardOfferRepository $offers,
        private JobboardPerimeter $perimeter,
    ) {
    }

    public function page(?User $reader, OfferFilters $filters, ?string $cursor, bool $admin): OfferPage
    {
        $tracks = $this->readableTracks($reader, $filters);

        if ([] === $tracks) {
            return new OfferPage([], null);
        }

        $qb = $this->offers->createOpenOffersQueryBuilder($tracks)
            ->addSelect('tr')
            ->leftJoin('o.track', 'tr')
            ->orderBy('o.sortDate', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);

        $this->applyFilters($qb, $admin ? $filters : $filters->withoutAdminFilters());
        $this->applyCursor($qb, $cursor);

        /** @var list<JobboardOffer> $rows */
        $rows = $qb->getQuery()->getResult();

        // One row more than a page was asked for: its existence is the whole answer to "is there
        // more?", and it is dropped rather than shown.
        $hasMore = \count($rows) > self::PAGE_SIZE;
        $rows = \array_slice($rows, 0, self::PAGE_SIZE);
        $last = $rows[\count($rows) - 1] ?? null;

        return new OfferPage($rows, $hasMore && null !== $last ? self::encodeCursor($last) : null);
    }

    /**
     * One offer, or null - and null covers "outside your perimeter" as well as "does not exist".
     * The detail panel must not be a way of reading a filière one is not in.
     */
    public function find(?User $reader, int $id): ?JobboardOffer
    {
        $tracks = $this->perimeter->tracks($reader);

        if ([] === $tracks) {
            return null;
        }

        $offer = $this->offers->createOpenOffersQueryBuilder($tracks)
            ->andWhere('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $offer instanceof JobboardOffer ? $offer : null;
    }

    /**
     * The values the « Catégorie », « Région », « Pays » and « Source » menus offer: what is
     * actually in the reader's perimeter, never a list written in advance. A menu proposing
     * « Bretagne » to somebody whose filière has no Breton offer is a filter that returns nothing.
     *
     * @return list<string>
     */
    public function distinctValues(?User $reader, string $field): array
    {
        $tracks = $this->perimeter->tracks($reader);

        if ([] === $tracks || !\in_array($field, ['category', 'region', 'country', 'source'], true)) {
            return [];
        }

        $rows = $this->offers->createOpenOffersQueryBuilder($tracks)
            ->select('DISTINCT o.'.$field.' AS value')
            ->andWhere('o.'.$field.' IS NOT NULL')
            ->orderBy('o.'.$field, 'ASC')
            ->getQuery()
            ->getScalarResult();

        $values = [];
        foreach ($rows as $row) {
            $value = \is_array($row) ? ($row['value'] ?? null) : null;

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if (\is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The perimeter, narrowed by the « Filières » filter when one is ticked. Intersection, never
     * substitution.
     *
     * @return list<Track>
     */
    private function readableTracks(?User $reader, OfferFilters $filters): array
    {
        $tracks = $this->perimeter->tracks($reader);

        if ([] === $filters->trackIds) {
            return $tracks;
        }

        return array_values(array_filter(
            $tracks,
            static fn (Track $track): bool => \in_array($track->getId(), $filters->trackIds, true),
        ));
    }

    private function applyFilters(QueryBuilder $qb, OfferFilters $filters): void
    {
        if (null !== $filters->departement) {
            $qb->andWhere('o.departement = :departement')->setParameter('departement', $filters->departement);
        }

        if (null !== $filters->firstSeenFrom) {
            $qb->andWhere('o.firstSeenAt >= :firstSeenFrom')->setParameter('firstSeenFrom', $filters->firstSeenFrom);
        }

        $this->applyIn($qb, 'contract', 'contracts', $filters->contracts);
        $this->applyIn($qb, 'category', 'categories', $filters->categories);
        $this->applyIn($qb, 'remote', 'remotes', $filters->remotes);
        $this->applyIn($qb, 'country', 'countries', $filters->countries);
        $this->applyIn($qb, 'region', 'regions', $filters->regions);
        $this->applyIn($qb, 'source', 'sources', $filters->sources);
        $this->applyIn($qb, 'btsAccess', 'btsAccess', $filters->btsAccess);
    }

    /** @param list<string|\BackedEnum> $values */
    private function applyIn(QueryBuilder $qb, string $field, string $parameter, array $values): void
    {
        if ([] === $values) {
            return;
        }

        $qb->andWhere('o.'.$field.' IN (:'.$parameter.')')->setParameter($parameter, $values);
    }

    private function applyCursor(QueryBuilder $qb, ?string $cursor): void
    {
        $decoded = self::decodeCursor($cursor);

        if (null === $decoded) {
            return;
        }

        [$date, $id] = $decoded;

        $qb->andWhere('o.sortDate < :cursorDate OR (o.sortDate = :cursorDate AND o.id < :cursorId)')
            ->setParameter('cursorDate', $date)
            ->setParameter('cursorId', $id);
    }

    private static function encodeCursor(JobboardOffer $offer): string
    {
        return rtrim(strtr(base64_encode($offer->getSortDate()->format('Y-m-d').'|'.$offer->getId()), '+/', '-_'), '=');
    }

    /**
     * A cursor that does not decode is ignored rather than refused: it comes back from a page the
     * reader has had open for a while, and starting the list again is a better answer than an error.
     *
     * @return array{\DateTimeImmutable, int}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (false === $decoded || !str_contains($decoded, '|')) {
            return null;
        }

        [$rawDate, $rawId] = explode('|', $decoded, 2);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);

        if (false === $date || !ctype_digit($rawId)) {
            return null;
        }

        return [$date, (int) $rawId];
    }
}
