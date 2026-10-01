<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\EnterpriseHosting;
use App\Entity\JobApplication;
use App\Entity\JobSearch;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use App\Enum\HostingKind;
use App\Enum\HostingSource;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\Sirene\SireneUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Terminer la recherche » with what it ended on (design/validated/vivier-entreprises.md §6.4) -
 * how the vivier feeds itself.
 *
 *   - **Stage trouvé** on a démarche about an establishment of the register: the stage enters the
 *     vivier for this school year, the class's filière and the student's option. The establishment
 *     becomes an employer of ours if it was not one, its SIRET confirmed - the teacher sees it named
 *     on the closing screen (R1).
 *   - **Alternance trouvée**: nothing is written to the vivier. The UFA's contract will say it,
 *     and saying it twice is what R3 forbids.
 *   - **Autre / sans suite**: the search closes, nothing else.
 *
 * Does not flush.
 */
class SearchOutcomeRecorder
{
    public function __construct(
        private readonly CompanySearchService $search,
        private readonly RegistrySnapshot $snapshot,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return bool false when a stage could not reach the vivier (no establishment, register down)
     */
    public function record(JobSearch $search, ?HostingKind $kind, ?JobApplication $application, Program $program, User $by): bool
    {
        $search->setOutcome($kind, $application);
        if (HostingKind::Stage !== $kind || null === $application || null === $application->getSiret()) {
            return HostingKind::Stage !== $kind;
        }

        $enterprise = $application->getEnterprise();
        if (null === $enterprise) {
            try {
                $company = $this->search->company($application->getSiret());
            } catch (SireneUnavailableException) {
                return false;
            }
            if (null === $company) {
                return false;
            }
            $enterprise = $this->snapshot->enterpriseFor($company, $by);
            $application->setEnterprise($enterprise);
        }

        $student = $search->getStudent();
        $year = $this->schoolYearOf($program);
        $already = $this->entityManager->getRepository(EnterpriseHosting::class)->findOneBy([
            'enterprise' => $enterprise, 'kind' => HostingKind::Stage, 'yearStart' => $year, 'student' => $student, 'inactiveDate' => null,
        ]);
        if (null !== $already || null === $program->getCohort()?->getTrack()) {
            return null !== $already;
        }

        $option = null;
        if (null !== $student) {
            $row = $this->entityManager->getRepository(ProgramStudentOption::class)->findOneBy(['program' => $program, 'student' => $student]);
            $option = $row?->getOption();
        }

        $this->entityManager->persist((new EnterpriseHosting())
            ->setEnterprise($enterprise)
            ->setKind(HostingKind::Stage)
            ->setYearStart($year)
            ->setTrack($program->getCohort()->getTrack())
            ->setOption($option)
            ->setStudent($student)
            ->setSource(HostingSource::JobSearch)
            ->setCreatedBy($by));

        return true;
    }

    private function schoolYearOf(Program $program): int
    {
        $start = $program->getSchoolYear()?->getStartDate() ?? new \DateTimeImmutable();
        $year = (int) $start->format('Y');

        return (int) $start->format('n') >= 8 ? $year : $year - 1;
    }
}
