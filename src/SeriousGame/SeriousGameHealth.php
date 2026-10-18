<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The health of a game's question templates (design/validated/serious-games.md §8, « santé »): the
 * success observed per template version, over every class at once.
 *
 * A version whose questions almost nobody gets right - or that does much worse or much better than
 * the version it replaced - is more likely a defect of the template than of the students: « un
 * écart sur toutes les classes est un bug ». It is flagged for the administrator, never acted on.
 * Figures without names, read by the administrator only.
 *
 * @phpstan-type VersionHealth array{unit: string, version: int, answered: int, ok: int, dontKnow: int, rate: ?float, flag: ?string}
 */
final readonly class SeriousGameHealth
{
    /** Below this many answers, a rate says nothing. */
    public const int MIN_ANSWERS = 30;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, array<int, VersionHealth>> unit => version => health
     */
    public function forGame(SeriousGame $game): array
    {
        /** @var list<array{unit: string, version: int|string, answered: int|string, ok: int|string|null, dontKnow: int|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.unitCode AS unit', 'a.templateVersion AS version', 'COUNT(a.id) AS answered', 'SUM(CASE WHEN a.ok = true THEN 1 ELSE 0 END) AS ok', 'SUM(CASE WHEN a.dontKnow = true THEN 1 ELSE 0 END) AS dontKnow')
            ->from(SeriousGameAnswer::class, 'a')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->setParameter('game', $game)
            ->groupBy('a.unitCode', 'a.templateVersion')
            ->orderBy('a.unitCode')
            ->addOrderBy('a.templateVersion')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $answered = (int) $row['answered'];
            $ok = (int) $row['ok'];
            $out[$row['unit']][(int) $row['version']] = [
                'unit' => $row['unit'],
                'version' => (int) $row['version'],
                'answered' => $answered,
                'ok' => $ok,
                'dontKnow' => (int) $row['dontKnow'],
                'rate' => 0 === $answered ? null : $ok / $answered,
                'flag' => null,
            ];
        }

        foreach ($out as $unit => $versions) {
            $previous = null;
            foreach ($versions as $version => $health) {
                $out[$unit][$version]['flag'] = self::flag($health, $previous);
                $previous = $health;
            }
        }

        return $out;
    }

    /**
     * @param VersionHealth      $health
     * @param VersionHealth|null $previous
     */
    public static function flag(array $health, ?array $previous): ?string
    {
        if ($health['answered'] < self::MIN_ANSWERS || null === $health['rate']) {
            return null;
        }

        if ($health['rate'] < .15) {
            return 'seriousGameHealthFlagLow';
        }

        if (null !== $previous && $previous['answered'] >= self::MIN_ANSWERS && null !== $previous['rate'] && abs($health['rate'] - $previous['rate']) > .2) {
            return 'seriousGameHealthFlagShift';
        }

        return null;
    }
}
