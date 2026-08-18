<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Entity\Program;
use App\Entity\ProgramStudentModality;
use App\Entity\ProgramStudentOption;
use App\Entity\StudentImportBatch;
use App\Entity\StudentImportBatchLine;
use App\Entity\User;
use App\Enum\ClassImportAction;
use App\Enum\StudentImportLineAction;
use App\Repository\GroupRepository;
use App\Repository\ModalityRepository;
use App\Repository\OptionRepository;
use App\Repository\UserRepository;
use App\Service\ContactEmailVerifier;
use App\Service\NewAccountRequest;
use App\Service\StudentAccountFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only place the class import writes anything, and it writes everything at once.
 *
 * One transaction for the whole file: the thirty accounts go in together or not at all. That
 * matters more here than elsewhere because the consumer on the other side runs every minute - a
 * half-written import would already be half-provisioned in the directory by the time anybody
 * noticed.
 *
 * Nothing is decided here. The analysis it is handed has already said what each line does, and it
 * refuses outright to run against one that is no longer importable - the controller rebuilds that
 * analysis from the parked rows immediately before calling, precisely so that somebody having
 * created one of these accounts in the meantime stops the import rather than duplicating them.
 */
class ClassImportExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StudentAccountFactory $accountFactory,
        private readonly ContactEmailVerifier $contactEmailVerifier,
        private readonly UserRepository $userRepository,
        private readonly GroupRepository $groupRepository,
        private readonly OptionRepository $optionRepository,
        private readonly ModalityRepository $modalityRepository,
    ) {
    }

    /**
     * @param list<string> $groups directory groups step ① ticked
     *
     * @throws ClassImportNotExecutableException
     */
    public function execute(
        ClassImportAnalysis $analysis,
        Program $program,
        User $operator,
        array $groups,
        bool $mustChangePassword,
    ): StudentImportBatch {
        if (!$analysis->isImportable()) {
            throw new ClassImportNotExecutableException();
        }

        $batch = new StudentImportBatch($program, $analysis->fileName, $operator);

        // A test class writes no directory request and no School mail address: a demonstration
        // account has no Windows session to open and, reception being catch-all, nothing to
        // receive. The record is still a record, so the login is generated and reserved all the
        // same.
        $directoryAccount = !$program->isTestProgram();

        $this->entityManager->wrapInTransaction(function () use ($analysis, $program, $operator, $groups, $mustChangePassword, $batch, $directoryAccount): void {
            $this->entityManager->persist($batch);

            $counts = [
                StudentImportLineAction::Create->value => 0,
                StudentImportLineAction::Attach->value => 0,
                StudentImportLineAction::Update->value => 0,
            ];
            $reservedLogins = [];

            foreach ($analysis->students as $student) {
                $action = StudentImportLineAction::fromAnalysis($student->action);
                ++$counts[$action->value];

                [$user, $ldapRequest] = match ($student->action) {
                    ClassImportAction::Create => $this->create($student, $program, $operator, $groups, $mustChangePassword, $directoryAccount, $reservedLogins),
                    default => [$this->existing($student), null],
                };

                if (null === $user) {
                    // The account the operator recognised has disappeared between the analysis and
                    // this line. Nothing is guessed: the whole import is refused, which the caller
                    // turns back into the verification screen.
                    throw new ClassImportNotExecutableException();
                }

                if (ClassImportAction::Attach === $student->action) {
                    $this->attach($user, $program, $groups);
                }

                $this->fillContactEmail($user, $student);
                $program->addStudent($user);
                $this->addValues($program, $user, $student);

                $this->entityManager->persist(new StudentImportBatchLine($batch, $user, $action, $ldapRequest));
            }

            $batch
                ->setCreatedCount($counts[StudentImportLineAction::Create->value])
                ->setAttachedCount($counts[StudentImportLineAction::Attach->value])
                ->setUpdatedCount($counts[StudentImportLineAction::Update->value])
            ;
        });

        return $batch;
    }

    /**
     * @param list<string> $groups
     * @param list<string> $reservedLogins accumulated across the file: two students of the same
     *                                     class routinely fold to the same login base, and nothing
     *                                     is flushed while the transaction is open
     *
     * @return array{User, \App\Entity\LdapManageUser|null}
     */
    private function create(
        AnalyzedStudent $student,
        Program $program,
        User $operator,
        array $groups,
        bool $mustChangePassword,
        bool $directoryAccount,
        array &$reservedLogins,
    ): array {
        $account = $this->accountFactory->create(
            new NewAccountRequest(
                firstname: $student->firstname,
                lastname: $student->lastname,
                userType: 'student',
                addedBy: $operator->getUsername(),
                groups: $groups,
                contactEmail: $student->email,
                mustChangePassword: $mustChangePassword,
                testUser: $program->isTestProgram(),
                directoryAccount: $directoryAccount,
            ),
            $reservedLogins,
        );

        $reservedLogins[] = $account->login();

        return [$account->user, $account->directoryRequest];
    }

    private function existing(AnalyzedStudent $student): ?User
    {
        $id = $student->account?->id;

        return null !== $id ? $this->userRepository->find($id) : null;
    }

    /**
     * What a human does on the user record: tick manual groups, and nothing else. A group the
     * directory already grants is never added as a manual one - never inherited and granted at the
     * same time - and Samba membership is not touched: it serves the Windows session and the
     * shares, not this application's permissions.
     *
     * @param list<string> $groups
     */
    private function attach(User $user, Program $program, array $groups): void
    {
        // Only after an explicit "same actual person": that answer is the only thing that reopens
        // a disabled account, and the screen says so before it happens, not after.
        $user->setInactiveDate(null);

        $ldapRoles = $user->getLdapRoles();

        foreach ($groups as $name) {
            $group = $this->groupRepository->findOneByLdapCn($name);
            if (null === $group || \in_array($group->getRole(), $ldapRoles, true)) {
                continue;
            }

            $user->addManualGroup($group);
        }
    }

    /** Never overwritten: the file of a class is not a source of truth about somebody's address. */
    private function fillContactEmail(User $user, AnalyzedStudent $student): void
    {
        if (null === $student->email || null !== $user->getContactEmail()) {
            return;
        }

        $user->setContactEmail($student->email);
        // Staff acting on somebody's behalf is trusted outright - no confirmation mail, ever.
        $this->contactEmailVerifier->markVerifiedByStaff($user);
    }

    /** Additive, never subtractive: the import adds what is missing and removes nothing. */
    private function addValues(Program $program, User $user, AnalyzedStudent $student): void
    {
        foreach ($student->valuesToAdd as $value) {
            if ($value->modality) {
                $modality = $this->modalityRepository->find($value->id);
                if (null !== $modality) {
                    $this->entityManager->persist(new ProgramStudentModality($program, $user, $modality));
                }

                continue;
            }

            $option = $this->optionRepository->find($value->id);
            if (null !== $option) {
                $this->entityManager->persist(new ProgramStudentOption($program, $user, $option));
            }
        }
    }
}
