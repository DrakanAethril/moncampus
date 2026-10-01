<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\JobApplicationOrigin;
use App\Repository\EnterpriseRepository;
use App\Repository\JobApplicationRepository;
use App\Service\JobApplicationResolver;
use App\Service\Sirene\RegistryCompany;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Garder » and « Rattacher » - how a company of the register becomes one of a student's
 * démarches (design/validated/vivier-entreprises.md §6.2).
 *
 * A kept company **is** a démarche, « à écrire » until a mail goes out: no second list, no status,
 * and the teacher reads it where they already read démarches. The démarche is named after the
 * company - the student renames it at will - and carries the establishment's SIRET, and the
 * vivier's employer when the establishment knows one.
 *
 * Names stay unique per student and class (the constraint the Courrier pro relies on): keeping a
 * company whose name is already a démarche without establishment attaches it there; a démarche of
 * that name about **another** establishment refuses, rather than merging two companies.
 */
class CompanyLeads
{
    public function __construct(
        private readonly JobApplicationRepository $applications,
        private readonly JobApplicationResolver $resolver,
        private readonly EnterpriseRepository $enterprises,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{application: ?JobApplication, error: ?string} the error a translation key
     */
    public function keep(User $student, RegistryCompany $company): array
    {
        $establishment = $company->establishments[0];
        $existing = $this->applications->findForStudentBySirets($student, [$establishment->siret])[$establishment->siret] ?? null;
        if (null !== $existing) {
            return ['application' => $existing, 'error' => null];
        }

        $name = mb_substr($company->fullName, 0, 255);
        $program = $this->resolver->programFor($student);
        $application = $this->applications->findOneForStudentAndName($student, $program, $name);

        if (null !== $application && null !== $application->getSiret()) {
            return ['application' => null, 'error' => 'companyLeadNameTakenError'];
        }

        $application ??= (new JobApplication())
            ->setStudent($student)
            ->setProgram($program)
            ->setName($name)
            ->setOrigin(JobApplicationOrigin::Spontaneous);

        $this->linkTo($application, $student, $establishment->siret);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return ['application' => $application, 'error' => null];
    }

    /**
     * « Rattacher » - a démarche the student named by hand is about this establishment. Only a
     * démarche of theirs that names none yet: a SIRET, once set by them, is not moved by a click.
     */
    public function attach(User $student, JobApplication $application, string $siret): bool
    {
        if ($application->getStudent() !== $student || null !== $application->getSiret()) {
            return false;
        }

        $this->linkTo($application, $student, $siret);
        $this->entityManager->flush();

        return true;
    }

    /** Only a démarche with nothing sent or received leaves: once a mail exists, it is a trace. */
    public function remove(User $student, JobApplication $application): bool
    {
        if ($application->getStudent() !== $student || !$application->isToWrite()) {
            return false;
        }

        $this->entityManager->remove($application);
        $this->entityManager->flush();

        return true;
    }

    private function linkTo(JobApplication $application, User $student, string $siret): void
    {
        $application->setSiret($siret);
        $application->setEnterprise($this->enterprises->findConfirmedBySirets([$siret], $student)[$siret] ?? null);
    }
}
