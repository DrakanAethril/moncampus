<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\Enterprise;
use App\Entity\EnterpriseHosting;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use App\Enum\HostingKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The one reading of the vivier's hostings** (design/validated/vivier-entreprises.md §9): the
 * UFA's alternances read off their contracts, the stored history beside them, the duplicates
 * removed (R3) - a stored alternance for a student, a year and a company the UFA already knows is
 * the contract's, not a second one. The fiche, the result badges, the vivier list and the import
 * all read it here.
 *
 * A test account sees test contracts only, the same asymmetry as everywhere else.
 */
class EnterpriseHostings
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<HostingRecord> most recent first
     */
    public function forEnterprise(Enterprise $enterprise, ?User $viewer): array
    {
        return $this->forEnterprises([$enterprise], $viewer)[(int) $enterprise->getId()] ?? [];
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, HostingSummary> keyed by enterprise id, only those with a hosting
     */
    public function summaries(array $enterprises, ?User $viewer): array
    {
        $summaries = [];
        foreach ($this->forEnterprises($enterprises, $viewer) as $id => $records) {
            $summary = new HostingSummary();
            foreach ($records as $record) {
                $summary->add($record);
            }
            $summaries[$id] = $summary;
        }

        return $summaries;
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, list<HostingRecord>> keyed by enterprise id, most recent first
     */
    public function forEnterprises(array $enterprises, ?User $viewer): array
    {
        $enterprises = array_values(array_filter($enterprises, static fn (Enterprise $enterprise): bool => null !== $enterprise->getId()));
        if ([] === $enterprises) {
            return [];
        }

        /** @var list<InternshipTutorLink> $alternances */
        $alternances = $this->entityManager->getRepository(InternshipTutorLink::class)->findAllForEnterprises($enterprises);
        if (true !== $viewer?->isTestUser()) {
            $alternances = array_values(array_filter($alternances, static fn (InternshipTutorLink $link): bool => !$link->isTestAlternance()));
        }

        /** @var list<EnterpriseHosting> $stored */
        $stored = $this->entityManager->createQueryBuilder()
            ->select('h', 't', 'o', 's', 'c')
            ->from(EnterpriseHosting::class, 'h')
            ->leftJoin('h.track', 't')
            ->leftJoin('h.option', 'o')
            ->leftJoin('h.student', 's')
            ->leftJoin('h.contact', 'c')
            ->where('h.enterprise IN (:enterprises)')
            ->andWhere('h.inactiveDate IS NULL')
            ->setParameter('enterprises', $enterprises)
            ->getQuery()
            ->getResult();

        $options = $this->studentOptions($alternances);
        $records = [];
        $ufaKeys = [];

        foreach ($alternances as $link) {
            $enterpriseId = (int) $link->getEnterprise()?->getId();
            $program = $link->getProgram();
            $student = $link->getStudent();
            $year = $this->yearOf($link);
            $records[$enterpriseId][] = new HostingRecord(
                kind: HostingKind::Alternance,
                yearStart: $year,
                track: $program?->getCohort()?->getTrack(),
                option: $options[$program?->getId().'-'.$student?->getId()] ?? null,
                student: $student,
                studentName: null,
                missions: null,
                tutorName: null !== $link->getTutor() ? ($link->getTutor()->getDisplayName() ?? $link->getTutor()->getUsername()) : null,
                alternance: $link,
            );
            if (null !== $student) {
                $ufaKeys[$enterpriseId.'-'.$student->getId().'-'.$year] = true;
            }
        }

        foreach ($stored as $hosting) {
            $enterpriseId = (int) $hosting->getEnterprise()?->getId();
            $kind = $hosting->getKind();
            if (null === $kind) {
                continue;
            }
            // R3: an alternance the UFA already holds is the contract's.
            if (HostingKind::Alternance === $kind && null !== $hosting->getStudent()
                && isset($ufaKeys[$enterpriseId.'-'.$hosting->getStudent()->getId().'-'.$hosting->getYearStart()])) {
                continue;
            }
            $records[$enterpriseId][] = new HostingRecord(
                kind: $kind,
                yearStart: $hosting->getYearStart(),
                track: $hosting->getTrack(),
                option: $hosting->getOption(),
                student: $hosting->getStudent(),
                studentName: $hosting->getStudentName(),
                missions: $hosting->getMissions(),
                tutorName: $hosting->getContact()?->getName(),
                hosting: $hosting,
            );
        }

        foreach ($records as &$list) {
            usort($list, static fn (HostingRecord $a, HostingRecord $b): int => [$b->yearStart, $a->kind->value] <=> [$a->yearStart, $b->kind->value]);
        }
        unset($list);

        return $records;
    }

    /**
     * The school year an alternance belongs to: its formation's, else the one its contract starts
     * in (August onwards is the next year).
     */
    public function yearOf(InternshipTutorLink $link): int
    {
        $start = $link->getProgram()?->getSchoolYear()?->getStartDate() ?? $link->getContractStartDate();
        if (null === $start) {
            return (int) date('Y');
        }

        $year = (int) $start->format('Y');

        return (int) $start->format('n') >= 8 ? $year : $year - 1;
    }

    /**
     * The option of each alternant in their formation, in one query.
     *
     * @param list<InternshipTutorLink> $alternances
     *
     * @return array<string, Option> keyed "programId-studentId"
     */
    private function studentOptions(array $alternances): array
    {
        $programs = [];
        $students = [];
        foreach ($alternances as $link) {
            if (null !== $link->getProgram() && null !== $link->getStudent()) {
                $programs[(int) $link->getProgram()->getId()] = $link->getProgram();
                $students[(int) $link->getStudent()->getId()] = $link->getStudent();
            }
        }
        if ([] === $programs) {
            return [];
        }

        /** @var list<ProgramStudentOption> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('pso', 'o')
            ->from(ProgramStudentOption::class, 'pso')
            ->join('pso.option', 'o')
            ->where('pso.program IN (:programs)')
            ->andWhere('pso.student IN (:students)')
            ->setParameter('programs', array_values($programs))
            ->setParameter('students', array_values($students))
            ->getQuery()
            ->getResult();

        $options = [];
        foreach ($rows as $row) {
            $key = $row->getProgram()?->getId().'-'.$row->getStudent()?->getId();
            if (null !== $row->getOption()) {
                $options[$key] ??= $row->getOption();
            }
        }

        return $options;
    }
}
