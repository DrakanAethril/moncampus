<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseNote;
use App\Entity\EnterpriseTeacherContact;
use App\Entity\User;
use App\Enum\HostingKind;
use App\Repository\EnterpriseRepository;
use App\Service\Sirene\RegistryCompany;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the vivier knows about a page of the register's results, in a handful of queries for the
 * whole page: which establishments are employers of ours (by confirmed SIRET, R8), what they
 * hosted, who on the team knows them - and, for a company known only at another of its
 * establishments, that much.
 *
 * Says everything it knows; deciding who may read the team's part is EnterpriseVoter's, which is
 * why the controller asks for `team` only when the voter said yes.
 *
 * @phpstan-type PoolEntry array{
 *     enterprise: Enterprise, summary: ?HostingSummary, teacherContacts: list<string>,
 *     noteCount: int, sharedContactCount: int
 * }
 */
class PoolAnnotations
{
    public function __construct(
        private readonly EnterpriseRepository $enterprises,
        private readonly EnterpriseHostings $hostings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<RegistryCompany> $companies
     *
     * @return array{establishments: array<string, PoolEntry>, elsewhere: array<string, list<HostingKind>>}
     */
    public function forCompanies(array $companies, User $viewer, bool $team): array
    {
        $sirets = [];
        $sirens = [];
        foreach ($companies as $company) {
            $sirens[] = $company->siren;
            foreach ($company->establishments as $establishment) {
                $sirets[] = $establishment->siret;
            }
        }

        $known = $this->enterprises->findConfirmedBySirets($sirets, $viewer);
        $sameCompany = array_values(array_filter(
            $this->enterprises->findConfirmedBySirens($sirens, $viewer),
            static fn (Enterprise $enterprise): bool => !isset($known[(string) $enterprise->getSiret()]),
        ));

        $all = [...array_values($known), ...$sameCompany];
        $summaries = $this->hostings->summaries($all, $viewer);
        $teacherContacts = $team ? $this->teacherContactNames($all) : [];
        $noteCounts = $team ? $this->noteCounts($all) : [];
        $sharedCounts = $this->sharedContactCounts($all);

        $establishments = [];
        foreach ($known as $siret => $enterprise) {
            $id = (int) $enterprise->getId();
            $establishments[$siret] = [
                'enterprise' => $enterprise,
                'summary' => $summaries[$id] ?? null,
                'teacherContacts' => $teacherContacts[$id] ?? [],
                'noteCount' => $noteCounts[$id] ?? 0,
                'sharedContactCount' => $sharedCounts[$id] ?? 0,
            ];
        }

        $elsewhere = [];
        foreach ($sameCompany as $enterprise) {
            $summary = $summaries[(int) $enterprise->getId()] ?? null;
            $siren = substr((string) $enterprise->getSiret(), 0, 9);
            foreach (HostingKind::cases() as $kind) {
                if (null !== $summary && $summary->has($kind) && !\in_array($kind, $elsewhere[$siren] ?? [], true)) {
                    $elsewhere[$siren][] = $kind;
                }
            }
        }

        return ['establishments' => $establishments, 'elsewhere' => $elsewhere];
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, list<string>>
     */
    private function teacherContactNames(array $enterprises): array
    {
        if ([] === $enterprises) {
            return [];
        }

        /** @var list<EnterpriseTeacherContact> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('tc', 't')
            ->from(EnterpriseTeacherContact::class, 'tc')
            ->join('tc.teacher', 't')
            ->where('tc.enterprise IN (:enterprises)')
            ->setParameter('enterprises', $enterprises)
            ->getQuery()
            ->getResult();

        $names = [];
        foreach ($rows as $row) {
            $teacher = $row->getTeacher();
            if (null !== $teacher) {
                $names[(int) $row->getEnterprise()?->getId()][] = $teacher->getDisplayName() ?? $teacher->getUsername();
            }
        }

        return $names;
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, int>
     */
    private function noteCounts(array $enterprises): array
    {
        return $this->countBy(EnterpriseNote::class, $enterprises, '');
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, int>
     */
    private function sharedContactCounts(array $enterprises): array
    {
        return $this->countBy(EnterpriseContact::class, $enterprises, 'AND x.shareableWithStudents = true AND x.inactiveDate IS NULL');
    }

    /**
     * @param class-string     $entity
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, int>
     */
    private function countBy(string $entity, array $enterprises, string $condition): array
    {
        if ([] === $enterprises) {
            return [];
        }

        /** @var list<array{id: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(x.enterprise) AS id, COUNT(x.id) AS total FROM '.$entity.' x WHERE x.enterprise IN (:enterprises) '.$condition.' GROUP BY x.enterprise',
        )->setParameter('enterprises', $enterprises)->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
