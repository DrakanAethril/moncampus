<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Entity\Enterprise;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AlternanceImportAction;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Util\PersonName;

/**
 * Confronts a read file with the database and says what importing it would do - without writing
 * anything, and without ever deciding anything on its own.
 *
 * This is the half of the contract import that matters. The screen it feeds is the only moment a
 * human sees the file and the platform side by side, so every judgement it makes has to be visible
 * as a finding rather than applied silently:
 *
 *  - **The student must already exist**, matched on their personal e-mail first (the file's own
 *    column, which is exactly what User::$contactEmail holds) then on their name, folded through
 *    App\Util\PersonName so "DE ANDRADE Nathan" finds "Nathan De Andrade". Unknown, or matching two
 *    people, is blocking - the import never creates an alternant, and never guesses between two.
 *  - **The formation comes from the student**, not from the file's "Code classe": that code is the
 *    school's timetable label ("BACHELORAIS"), which nothing here maps to a Program. It is shown
 *    beside the resolved formation so the operator validates the pairing by eye, which is the whole
 *    point of the confirmation step.
 *  - **Employers and tutors may be new**, and are matched the way the rest of the UFA does it: an
 *    employer by folded name, a tutor by contact e-mail first (the operator's own rule) then by
 *    name, the latter only as a warning since reusing the wrong account is worse than the operator
 *    being asked to look.
 *  - **A student who already holds an alternance** is skipped when it is the same tutor, and is
 *    blocking when it is a different one - a second, contradictory contract is exactly the kind of
 *    thing an import must never resolve by itself.
 *
 * One blocking finding anywhere refuses the whole file (see ImportAnalysis::isImportable()).
 */
class ImportAnalyzer
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYearRepository,
        private readonly ProgramRepository $programRepository,
        private readonly EnterpriseRepository $enterpriseRepository,
        private readonly UserRepository $userRepository,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly ContractDateParser $dateParser,
    ) {
    }

    /**
     * @param list<ContractRow> $rows
     *
     * @throws ImportFileException when the platform itself is not in a state to receive an import
     */
    public function analyze(array $rows, string $fileName): ImportAnalysis
    {
        $schoolYear = $this->schoolYearRepository->findCurrentOrMostRecent()
            ?? throw new ImportFileException('ufaContractImportNoSchoolYearMessage');

        $programs = $this->programRepository->findAllActiveForSchoolYear($schoolYear);
        $studentsByName = $this->indexStudents($programs, static fn (User $student): string => PersonName::fold($student->getFirstname() ?? '', $student->getLastname() ?? ''));
        $studentsByEmail = $this->indexStudents($programs, static fn (User $student): string => mb_strtolower(trim($student->getContactEmail() ?? '')));

        $enterprisesByName = [];
        foreach ($this->enterpriseRepository->findAllActiveOrderedByName() as $enterprise) {
            $enterprisesByName[PersonName::fold($enterprise->getName())] ??= $enterprise;
        }

        [$usersByEmail, $tutorsByName] = $this->indexTutorCandidates();

        $analyzed = [];
        $seenStudentIds = [];
        foreach ($rows as $row) {
            $analyzed[] = $this->analyzeRow($row, $studentsByName, $studentsByEmail, $enterprisesByName, $usersByEmail, $tutorsByName, $seenStudentIds);
        }

        return $this->aggregate($fileName, $analyzed);
    }

    /**
     * @param array<string, list<array{User, Program}>> $studentsByName
     * @param array<string, list<array{User, Program}>> $studentsByEmail
     * @param array<string, Enterprise>                 $enterprisesByName
     * @param array<string, User>                       $usersByEmail
     * @param array<string, list<User>>                 $tutorsByName
     * @param array<int, int>                           $seenStudentIds student id => line already claiming them
     */
    private function analyzeRow(ContractRow $row, array $studentsByName, array $studentsByEmail, array $enterprisesByName, array $usersByEmail, array $tutorsByName, array &$seenStudentIds): AnalyzedRow
    {
        $issues = [];

        [$student, $program] = $this->resolveStudent($row, $studentsByName, $studentsByEmail, $issues);

        if (null !== $student && null !== $program) {
            $studentId = $student->getId() ?? 0;
            if (isset($seenStudentIds[$studentId])) {
                $issues[] = ImportIssue::blocking('ufaContractImportIssueDuplicateStudentInFile', ['%line%' => (string) $seenStudentIds[$studentId]]);
            }
            $seenStudentIds[$studentId] = $row->line;

            if (!$this->isAlternanceProgram($program)) {
                $issues[] = ImportIssue::blocking('ufaContractImportIssueProgramNotAlternance', ['%program%' => $program->getDisplayShortName()]);
            }
        }

        $period = $this->resolvePeriod($row, $program, $issues);
        $enterprise = $this->resolveEnterprise($row, $enterprisesByName, $issues);
        $tutor = $this->resolveTutor($row, $usersByEmail, $tutorsByName, $issues);

        $alreadyImported = false;
        if (null !== $student && null !== $program) {
            $existing = $this->tutorLinkRepository->findOneForStudentAndProgram($student, $program);
            if (null !== $existing) {
                $alreadyImported = true;
                $existingTutor = $existing->getTutor();

                if (null !== $tutor && null !== $existingTutor && $tutor->getId() === $existingTutor->getId()) {
                    $issues[] = ImportIssue::note('ufaContractImportIssueAlreadyImported', ['%tutor%' => $this->userLabel($existingTutor)]);
                } else {
                    // Two different people named as THE tutor of the same alternance: whichever is
                    // right, only a human knows which, and overwriting silently would rewrite a
                    // signed livret's counterpart.
                    $issues[] = ImportIssue::blocking('ufaContractImportIssueTutorConflict', [
                        '%existing%' => null !== $existingTutor ? $this->userLabel($existingTutor) : '—',
                        '%file%' => '' !== $row->tutorName ? $row->tutorName : $row->tutorEmail,
                    ]);
                }
            }
        }

        $blocking = [] !== array_filter($issues, static fn (ImportIssue $issue): bool => $issue->isBlocking());
        $action = match (true) {
            $blocking => AlternanceImportAction::Blocked,
            $alreadyImported => AlternanceImportAction::Skip,
            default => AlternanceImportAction::Create,
        };

        return new AnalyzedRow($row, $action, $issues, $student, $program, $enterprise, $tutor, $period, $alreadyImported);
    }

    /**
     * @param array<string, list<array{User, Program}>> $studentsByName
     * @param array<string, list<array{User, Program}>> $studentsByEmail
     * @param list<ImportIssue>                         $issues
     *
     * @return array{?User, ?Program}
     */
    private function resolveStudent(ContractRow $row, array $studentsByName, array $studentsByEmail, array &$issues): array
    {
        $email = mb_strtolower(trim($row->studentEmail));
        $candidates = ('' !== $email ? $studentsByEmail[$email] ?? [] : []);

        if ([] === $candidates) {
            $candidates = $studentsByName[PersonName::fold($row->studentName)] ?? [];
        }

        if ([] === $candidates) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueUnknownStudent', ['%student%' => $row->studentName]);

            return [null, null];
        }

        // A student enrolled in several of the year's Programs: the alternance one is the only
        // place this contract can hang, so it wins outright rather than being an ambiguity.
        $alternance = array_values(array_filter($candidates, fn (array $pair): bool => $this->isAlternanceProgram($pair[1])));
        if (1 === \count($alternance)) {
            return $alternance[0];
        }

        $candidates = [] !== $alternance ? $alternance : $candidates;

        if (1 < \count($candidates)) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueAmbiguousStudent', [
                '%student%' => $row->studentName,
                '%programs%' => implode(', ', array_map(static fn (array $pair): string => $pair[1]->getDisplayShortName(), $candidates)),
            ]);

            return [null, null];
        }

        return $candidates[0];
    }

    /** @param list<ImportIssue> $issues */
    private function resolvePeriod(ContractRow $row, ?Program $program, array &$issues): ?ContractPeriod
    {
        $period = $this->dateParser->parse($row->observations);

        if (null === $period) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueUnreadableDates', ['%value%' => '' !== $row->observations ? $row->observations : '—']);

            return null;
        }

        if (!$period->isChronological()) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueReversedDates', [
                '%start%' => $period->start->format('d/m/Y'),
                '%end%' => $period->end->format('d/m/Y'),
            ]);

            return $period;
        }

        $programStart = $program?->getEffectiveStartDate();
        $programEnd = $program?->getEffectiveEndDate();

        if (null === $programStart || null === $programEnd) {
            return $period;
        }

        // An apprenticeship contract legitimately runs longer than one formation (two-year BTS
        // contracts start a year before the second-year Program does), so overflowing is normal and
        // is NOT reported. What is reported is a contract that does not cover the formation:
        // no overlap at all usually means the wrong school year, and an early end means months of
        // the formation with no employer.
        if ($period->end < $programStart || $period->start > $programEnd) {
            $issues[] = ImportIssue::warning('ufaContractImportIssueDatesOutsideProgram', [
                '%start%' => $period->start->format('d/m/Y'),
                '%end%' => $period->end->format('d/m/Y'),
                '%programStart%' => $programStart->format('d/m/Y'),
                '%programEnd%' => $programEnd->format('d/m/Y'),
            ]);
        } elseif ($period->end < $programEnd) {
            $issues[] = ImportIssue::warning('ufaContractImportIssueContractEndsEarly', [
                '%end%' => $period->end->format('d/m/Y'),
                '%programEnd%' => $programEnd->format('d/m/Y'),
            ]);
        }

        return $period;
    }

    /**
     * @param array<string, Enterprise> $enterprisesByName
     * @param list<ImportIssue>         $issues
     */
    private function resolveEnterprise(ContractRow $row, array $enterprisesByName, array &$issues): ?Enterprise
    {
        if ('' === $row->enterpriseName) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueMissingEnterprise');

            return null;
        }

        return $enterprisesByName[PersonName::fold($row->enterpriseName)] ?? null;
    }

    /**
     * @param array<string, User>       $usersByEmail
     * @param array<string, list<User>> $tutorsByName
     * @param list<ImportIssue>         $issues
     */
    private function resolveTutor(ContractRow $row, array $usersByEmail, array $tutorsByName, array &$issues): ?User
    {
        $email = mb_strtolower(trim($row->tutorEmail));

        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueMissingTutorEmail', ['%tutor%' => '' !== $row->tutorName ? $row->tutorName : '—']);

            return null;
        }

        $byEmail = $usersByEmail[$email] ?? null;
        if (null !== $byEmail) {
            // Beaupeyrat itself takes apprentices, so a tutor's address is sometimes a colleague's
            // staff account. Reusing it is right - the alternance must not spawn a second account
            // for someone who already has one - but it is worth a look.
            if (!\in_array('ROLE_TUTOR', $byEmail->getRoles(), true)) {
                $issues[] = ImportIssue::warning('ufaContractImportIssueTutorAccountNotTutor', [
                    '%tutor%' => $this->userLabel($byEmail),
                    '%email%' => $email,
                ]);
            }

            return $byEmail;
        }

        $byName = $tutorsByName[PersonName::fold($row->tutorName)] ?? [];
        if (1 < \count($byName)) {
            // Two tutor accounts already share this name and neither holds the file's address:
            // creating a third is defensible, picking one of them is not.
            $issues[] = ImportIssue::warning('ufaContractImportIssueTutorAmbiguousName', ['%tutor%' => $row->tutorName]);
        } elseif (1 === \count($byName)) {
            $issues[] = ImportIssue::warning('ufaContractImportIssueTutorMatchedByName', [
                '%tutor%' => $this->userLabel($byName[0]),
                '%existingEmail%' => $byName[0]->getContactEmail() ?? '—',
                '%fileEmail%' => $email,
            ]);

            return $byName[0];
        }

        if ('' === trim($row->tutorName)) {
            $issues[] = ImportIssue::blocking('ufaContractImportIssueMissingTutorName', ['%email%' => $email]);
        }

        return null;
    }

    /**
     * Every user who could already be the tutor named in the file: indexed by contact e-mail (any
     * account, whatever its role - see resolveTutor()) and, for tutor accounts only, by folded name.
     *
     * @return array{array<string, User>, array<string, list<User>>}
     */
    private function indexTutorCandidates(): array
    {
        $byEmail = [];
        foreach ($this->userRepository->findAllWithContactEmail() as $user) {
            $byEmail[mb_strtolower(trim($user->getContactEmail() ?? ''))] ??= $user;
        }

        $byName = [];
        foreach ($this->userRepository->findActiveMatchingRoles(['ROLE_TUTOR']) as $tutor) {
            $byName[PersonName::fold($tutor->getFirstname() ?? '', $tutor->getLastname() ?? '')][] = $tutor;
        }

        return [$byEmail, $byName];
    }

    /**
     * @param list<Program>          $programs
     * @param callable(User): string $key
     *
     * @return array<string, list<array{User, Program}>>
     */
    private function indexStudents(array $programs, callable $key): array
    {
        $index = [];
        foreach ($programs as $program) {
            foreach ($program->getStudents() as $student) {
                $value = $key($student);
                if ('' !== $value) {
                    $index[$value][] = [$student, $program];
                }
            }
        }

        return $index;
    }

    private function isAlternanceProgram(Program $program): bool
    {
        foreach ($program->getModalities() as $modality) {
            if ($modality->isAlternance()) {
                return true;
            }
        }

        return false;
    }

    private function userLabel(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '').' '.($user->getLastname() ?? ''));

        return '' !== $name ? $name : $user->getUsername();
    }

    /** @param list<AnalyzedRow> $rows */
    private function aggregate(string $fileName, array $rows): ImportAnalysis
    {
        $newEnterprises = [];
        $knownEnterprises = [];
        $newTutors = [];
        $knownTutors = [];

        // Keyed the way the WRITE deduplicates - folded company name, lowercased tutor address -
        // rather than by the raw spelling: the file names the same employer "FONDERIE NOVA" on one
        // line and "Fonderie  Nova" on the next, and a summary announcing two new companies where
        // one will be created is exactly what the operator is being asked to validate.
        foreach ($rows as $row) {
            if (null !== $row->enterprise) {
                $knownEnterprises[PersonName::fold($row->enterprise->getName())] ??= $row->enterprise->getName();
            } elseif ('' !== $row->row->enterpriseName) {
                $newEnterprises[PersonName::fold($row->row->enterpriseName)] ??= $row->row->enterpriseName;
            }

            if (null !== $row->tutor) {
                $email = $row->tutor->getContactEmail() ?? '';
                $knownTutors[mb_strtolower('' !== $email ? $email : $row->tutor->getUsername())] ??= \sprintf('%s <%s>', $this->userLabel($row->tutor), '' !== $email ? $email : '—');
            } elseif ('' !== $row->row->tutorEmail) {
                $email = mb_strtolower($row->row->tutorEmail);
                $newTutors[$email] ??= \sprintf('%s <%s>', $row->row->tutorName, $email);
            }
        }

        return new ImportAnalysis(
            $fileName,
            $rows,
            array_values($newEnterprises),
            array_values($knownEnterprises),
            array_values($newTutors),
            array_values($knownTutors),
        );
    }
}
