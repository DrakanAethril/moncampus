<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\AlternanceImportAction;
use App\Enum\ContractTypeCode;
use App\Service\AlternanceEngagementService;
use App\Service\AlternanceModalityAssigner;
use App\Service\InternshipTutorProvisioningService;
use App\Util\PersonName;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes an analysis that the operator has confirmed - and nothing else.
 *
 * Three properties matter more here than anywhere else in the import:
 *
 *  - **All or nothing.** The whole run is one transaction. Half a promotion's contracts, with the
 *    other half missing because line 40 hit a constraint, is the failure mode that would cost the
 *    most to unpick by hand.
 *  - **It re-checks.** The caller hands over a *freshly re-analysed* ImportAnalysis, not the one
 *    the operator looked at, and this refuses anything still blocking. Between the two screens
 *    someone else may have created that very alternance.
 *  - **It stays silent.** Tutor accounts are provisioned exactly as staff-created ones are (contact
 *    e-mail marked verified outright, see InternshipTutorProvisioningService), and the livret
 *    engagement is created *without* its invitation mails: importing three years of existing
 *    contracts must not send 52 people a mail about a booklet they were never told about. Staff
 *    send the invites from the alternance's own screen, when they mean to.
 *
 * Employers and tutors are deduplicated across the run: the file names the same company on several
 * lines, and three tutors follow two students each.
 */
class ImportExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternshipTutorProvisioningService $tutorProvisioningService,
        private readonly AlternanceEngagementService $engagementService,
        private readonly AlternanceModalityAssigner $modalityAssigner,
        private readonly EnterpriseAddress $addressParser,
    ) {
    }

    /** @throws \DomainException when the analysis is no longer importable */
    public function execute(ImportAnalysis $analysis, User $operator): ImportOutcome
    {
        if (!$analysis->isImportable()) {
            throw new \DomainException('Refusing to import an analysis that is not importable.');
        }

        $createdEnterprises = [];
        $createdTutors = [];
        $taggedStudents = [];
        $skippedStudents = [];
        $created = 0;

        $this->entityManager->wrapInTransaction(function () use ($analysis, $operator, &$createdEnterprises, &$createdTutors, &$taggedStudents, &$skippedStudents, &$created): void {
            /** @var array<string, Enterprise> $enterprisesByName employers created earlier in this same run */
            $enterprisesByName = [];
            /** @var array<string, User> $tutorsByEmail */
            $tutorsByEmail = [];

            foreach ($analysis->rows as $analyzedRow) {
                if (AlternanceImportAction::Skip === $analyzedRow->action) {
                    $skippedStudents[] = $this->studentLabel($analyzedRow);
                    continue;
                }

                $student = $analyzedRow->student;
                $program = $analyzedRow->program;
                $period = $analyzedRow->period;

                // Guaranteed by isImportable() - a row missing any of these carries a blocking
                // finding, and this method refused the analysis above.
                if (null === $student || null === $program || null === $period) {
                    continue;
                }

                $tutorLink = new InternshipTutorLink($program);
                $tutorLink->setStudent($student);
                $tutorLink->setContractStartDate($period->start);
                $tutorLink->setContractEndDate($period->end);
                // The file is an apprenticeship export ("contrat apprentissage uniquement"); it
                // carries no contract type of its own to read.
                $tutorLink->setContractType(ContractTypeCode::Apprentissage);
                $tutorLink->setSupervisor($program->getReferentTeachers()->first() ?: null);
                $tutorLink->setCreatedBy($operator);

                $tutorLink->setEnterprise($this->resolveEnterprise($analyzedRow, $operator, $enterprisesByName, $createdEnterprises));
                $this->resolveTutor($analyzedRow, $operator, $tutorLink, $tutorsByEmail, $createdTutors);

                $this->entityManager->persist($tutorLink);

                if ($this->modalityAssigner->ensureTagged($program, $student)) {
                    $taggedStudents[] = $this->studentLabel($analyzedRow);
                }

                $this->entityManager->flush();

                // Same call the manual creation screen makes, minus sendEngagementInvites() - see
                // this class's docblock.
                $this->engagementService->findOrCreate($tutorLink);

                ++$created;
            }
        });

        return new ImportOutcome($created, $createdEnterprises, $createdTutors, $taggedStudents, $skippedStudents);
    }

    /**
     * @param array<string, Enterprise> $enterprisesByName
     * @param list<string>              $createdEnterprises
     */
    private function resolveEnterprise(AnalyzedRow $analyzedRow, User $operator, array &$enterprisesByName, array &$createdEnterprises): Enterprise
    {
        if (null !== $analyzedRow->enterprise) {
            return $analyzedRow->enterprise;
        }

        $name = $analyzedRow->row->enterpriseName;
        $key = PersonName::fold($name);

        if (isset($enterprisesByName[$key])) {
            return $enterprisesByName[$key];
        }

        $enterprise = new Enterprise($name, $this->addressParser->postalAddress($analyzedRow->row->enterpriseAddress, $name));
        $enterprise->setCity($this->addressParser->city($analyzedRow->row->enterpriseAddress));
        $enterprise->setCreatedBy($operator);

        $this->entityManager->persist($enterprise);

        $enterprisesByName[$key] = $enterprise;
        $createdEnterprises[] = $name;

        return $enterprise;
    }

    /**
     * @param array<string, User> $tutorsByEmail
     * @param list<string>        $createdTutors
     */
    private function resolveTutor(AnalyzedRow $analyzedRow, User $operator, InternshipTutorLink $tutorLink, array &$tutorsByEmail, array &$createdTutors): void
    {
        if (null !== $analyzedRow->tutor) {
            $tutorLink->setTutor($analyzedRow->tutor);

            return;
        }

        $email = mb_strtolower(trim($analyzedRow->row->tutorEmail));

        // Same person, second contract: reuse the account provisioned a few lines ago rather than
        // queueing a second LDAP creation for an address that is unique platform-wide.
        if (isset($tutorsByEmail[$email])) {
            $tutorLink->setTutor($tutorsByEmail[$email]);

            return;
        }

        $name = PersonName::split($analyzedRow->row->tutorName);
        $tutor = $this->tutorProvisioningService->provision(
            $tutorLink,
            $name['firstname'],
            $name['lastname'],
            $email,
            $analyzedRow->row->tutorBestPhone(),
            false,
            $operator,
        );

        $tutorsByEmail[$email] = $tutor;
        $createdTutors[] = \sprintf('%s %s <%s>', $name['firstname'], $name['lastname'], $email);
    }

    private function studentLabel(AnalyzedRow $analyzedRow): string
    {
        $student = $analyzedRow->student;

        return null !== $student ? ($student->getDisplayName() ?? $student->getUsername()) : $analyzedRow->row->studentName;
    }
}
