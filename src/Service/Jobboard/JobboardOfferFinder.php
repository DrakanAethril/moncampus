<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardOffer;
use App\Entity\JobboardSource;
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
 * - **the order is (first_seen_at DESC, id DESC)** and the cursor is that exact pair. It is the day
 *   the veille brought the advert back, not the day the advert says it was published, and the
 *   difference is the collecting agents themselves: they do not all pass every day, so ordering on
 *   the publication date pushes a whole week of a rarely-run agent's harvest down the list, where
 *   nobody scrolls. `first_seen_at` is also the only date here that is never null and never moves,
 *   which is what makes it a stable cursor.
 */
final readonly class JobboardOfferFinder
{
    public const int PAGE_SIZE = 40;

    private const string CURSOR_FORMAT = 'Y-m-d H:i:s';

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
            ->addSelect('tr', 'so')
            ->leftJoin('o.track', 'tr')
            // Joined rather than lazily loaded: the source carries the trade name every row of an
            // administrator's list prints, and forty lazy proxies is forty queries.
            ->leftJoin('o.source', 'so')
            ->orderBy('o.firstSeenAt', 'DESC')
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
     * The sites the « Source » menu offers - the rows actually present in the reader's perimeter.
     * Administrators only see this filter at all, but the perimeter is applied here just the same:
     * a query is not the place to trust who is asking.
     *
     * @return list<JobboardSource>
     */
    public function sources(?User $reader): array
    {
        $tracks = $this->perimeter->tracks($reader);

        return [] === $tracks ? [] : $this->offers->findSourcesInPerimeter($tracks);
    }

    /**
     * The values the « Catégorie », « Région » and « Pays » menus offer: what is actually in the
     * reader's perimeter, never a list written in advance. A menu proposing « Bretagne » to
     * somebody whose filière has no Breton offer is a filter that returns nothing.
     *
     * @return list<string>
     */
    public function distinctValues(?User $reader, string $field): array
    {
        $tracks = $this->perimeter->tracks($reader);

        if ([] === $tracks || !\in_array($field, ['category', 'region', 'country'], true)) {
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

        if ([] !== $filters->sources) {
            $qb->andWhere('so.slug IN (:sources)')->setParameter('sources', $filters->sources);
        }

        $this->applyIn($qb, 'contract', 'contracts', $filters->contracts);
        $this->applyIn($qb, 'category', 'categories', $filters->categories);
        $this->applyIn($qb, 'remote', 'remotes', $filters->remotes);
        $this->applyIn($qb, 'country', 'countries', $filters->countries);
        $this->applyIn($qb, 'region', 'regions', $filters->regions);
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

        $qb->andWhere('o.firstSeenAt < :cursorDate OR (o.firstSeenAt = :cursorDate AND o.id < :cursorId)')
            ->setParameter('cursorDate', $date)
            ->setParameter('cursorId', $id);
    }

    /**
     * The cursor carries the whole timestamp, to the second. A date alone would be ambiguous: a
     * batch files its offers within the same minute, so a day's worth of rows share a first-seen
     * *date* and the pair (date, id) would no longer be the order the list is read in.
     */
    private static function encodeCursor(JobboardOffer $offer): string
    {
        return rtrim(strtr(base64_encode($offer->getFirstSeenAt()->format(self::CURSOR_FORMAT).'|'.$offer->getId()), '+/', '-_'), '=');
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
        $date = \DateTimeImmutable::createFromFormat('!'.self::CURSOR_FORMAT, $rawDate);

        if (false === $date || !ctype_digit($rawId)) {
            return null;
        }

        return [$date, (int) $rawId];
    }
}
