<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Enum\ClassImportAction;
use App\Service\AsciiFolder;

/**
 * Decides what the import would do with every line, and everything it has to say first. Writes
 * nothing, reads nothing: the database was already read into a ClassImportContext, which is what
 * makes every rule below testable one case at a time.
 *
 * Two rules run through the whole class and are worth stating once:
 *
 * - **Lines are matched on the name, never on the address.** Two siblings sometimes share a
 *   mailbox, and an import must not merge two people for that reason.
 * - **A namesake outside the class is never resolved here.** Two students of two classes can
 *   genuinely be two different people carrying the same name; the login and the School mail
 *   address already know how to tell them apart, so creating a namesake is harmless while
 *   confusing one with somebody else is not. The line waits for the operator, and blocks.
 */
class ClassImportAnalyzer
{
    public function __construct(private readonly NameNormalizer $nameNormalizer)
    {
    }

    /**
     * @param list<StudentRow>      $rows
     * @param array<int, int|null>  $decisions line number => the account the operator recognised,
     *                                         or null for "namesake, create a new account". A line
     *                                         absent from the map has not been answered at all,
     *                                         which is why array_key_exists is what reads it.
     */
    public function analyze(array $rows, ClassImportContext $context, array $decisions, string $fileName = ''): ClassImportAnalysis
    {
        $duplicateNames = $this->duplicateLines($rows, fn (StudentRow $row): string => $this->keyFor($row)->value);
        $duplicateEmails = $this->duplicateLines($rows, static fn (StudentRow $row): string => mb_strtolower(trim($row->email)));

        $students = [];
        foreach ($rows as $row) {
            $students[] = $this->analyzeRow($row, $context, $decisions, $duplicateNames, $duplicateEmails);
        }

        return new ClassImportAnalysis($fileName, $students);
    }

    /**
     * @param array<int, int|null>       $decisions
     * @param array<int, list<int>>      $duplicateNames  line => the other lines naming the same person
     * @param array<int, list<int>>      $duplicateEmails line => the other lines carrying the same address
     */
    private function analyzeRow(
        StudentRow $row,
        ClassImportContext $context,
        array $decisions,
        array $duplicateNames,
        array $duplicateEmails,
    ): AnalyzedStudent {
        $issues = [];
        $firstname = $this->nameNormalizer->normalize($row->firstname);
        $lastname = $this->nameNormalizer->normalize($row->lastname);

        if ('' === $lastname) {
            $issues[] = ImportIssue::blocking('classImportIssueMissingLastnameMessage');
        }
        if ('' === $firstname) {
            $issues[] = ImportIssue::blocking('classImportIssueMissingFirstnameMessage');
        }

        $email = '' !== trim($row->email) ? trim($row->email) : null;
        if (null === $email) {
            $issues[] = ImportIssue::warning('classImportWarningNoEmailMessage');
        } elseif (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $issues[] = ImportIssue::blocking('classImportIssueInvalidEmailMessage', ['%email%' => $email]);
            $email = null;
        }

        if ([] !== ($others = $duplicateNames[$row->line] ?? [])) {
            $issues[] = ImportIssue::blocking('classImportIssueDuplicateInFileMessage', [
                '%name%' => trim($firstname.' '.$lastname),
                '%otherLine%' => implode(', ', $others),
            ]);
        }

        if (null !== $email && [] !== ($others = $duplicateEmails[$row->line] ?? [])) {
            $issues[] = ImportIssue::blocking('classImportIssueDuplicateEmailInFileMessage', [
                '%email%' => $email,
                '%otherLine%' => implode(', ', $others),
            ]);
        }

        [$action, $account, $candidates] = $this->resolveAccount($row, $context, $decisions);

        if (null !== $account && !$account->isStudent()) {
            // A class import never changes what an account is - the file says who is in the class,
            // not what they are.
            $issues[] = ImportIssue::blocking(
                'classImportIssueNotAStudentMessage',
                ['%name%' => $account->displayName()],
                ['%type%' => $account->userTypeLabelKey()],
            );
        }

        if (null !== $email) {
            $owner = $context->ownerOfEmail($email);
            if (null !== $owner && $owner[0] !== $account?->id) {
                $issues[] = ImportIssue::blocking('classImportIssueEmailTakenMessage', [
                    '%email%' => $email,
                    '%owner%' => $owner[1],
                ]);
            }
        }

        $fillsEmail = null !== $email && null === $account?->contactEmail;
        if (null !== $email && null !== $account?->contactEmail && $account->contactEmail !== $email) {
            // Never overwritten: the file of a class is not a source of truth about somebody's own
            // address, and the one already on the account may be the one they actually read.
            $issues[] = ImportIssue::warning('classImportWarningEmailKeptMessage', [
                '%existing%' => $account->contactEmail,
                '%ignored%' => $email,
            ]);
        }

        if (ClassImportAction::Attach === $action && null !== $account && !$account->active) {
            $issues[] = ImportIssue::warning('classImportWarningReactivationMessage', ['%login%' => $account->login]);
        }

        if (null !== $account && $account->inAnotherProgramOfTheSameYear) {
            $issues[] = ImportIssue::warning('classImportWarningTwoProgramsMessage', [
                '%program%' => $account->programLabel,
            ]);
        }

        [$valuesToAdd, $valueIssues] = $this->resolveValues($row, $context, $account);
        $issues = [...$issues, ...$valueIssues];

        $rawName = trim(trim($row->firstname).' '.trim($row->lastname));
        $normalizedName = trim($firstname.' '.$lastname);
        if ($rawName !== $normalizedName) {
            // Shown before anything is written: firstname and lastname become the directory's
            // property and are read-only on the record afterwards.
            $issues[] = ImportIssue::note('classImportNoteNameNormalizedMessage', [
                '%raw%' => $rawName,
                '%normalized%' => $normalizedName,
            ]);
        }

        // One blocking finding anywhere refuses the whole file, so the line's own verdict stops
        // mattering the moment it carries one.
        if ([] !== array_filter($issues, static fn (ImportIssue $issue): bool => $issue->isBlocking())) {
            $action = ClassImportAction::Blocked;
        }

        return new AnalyzedStudent(
            line: $row->line,
            firstname: $firstname,
            lastname: $lastname,
            rawFirstname: trim($row->firstname),
            rawLastname: trim($row->lastname),
            email: $email,
            action: $action,
            issues: $issues,
            valuesToAdd: $valuesToAdd,
            candidates: $candidates,
            account: $account,
            fillsEmail: $fillsEmail,
        );
    }

    /**
     * @param array<int, int|null> $decisions
     *
     * @return array{ClassImportAction, ExistingAccount|null, list<ExistingAccount>}
     */
    private function resolveAccount(StudentRow $row, ClassImportContext $context, array $decisions): array
    {
        $candidates = $context->accountsNamed($this->keyFor($row));

        foreach ($candidates as $candidate) {
            // Already a student of this class: nothing to decide, and nothing to do beyond the
            // missing address and the missing options. This is what makes a re-import idempotent.
            if ($candidate->inDestinationProgram) {
                return [ClassImportAction::Update, $candidate, []];
            }
        }

        if ([] === $candidates) {
            return [ClassImportAction::Create, null, []];
        }

        if (!\array_key_exists($row->line, $decisions)) {
            return [ClassImportAction::Decide, null, $candidates];
        }

        $chosen = $decisions[$row->line];
        if (null === $chosen) {
            return [ClassImportAction::Create, null, []];
        }

        foreach ($candidates as $candidate) {
            if ($candidate->id === $chosen) {
                return [ClassImportAction::Attach, $candidate, []];
            }
        }

        // The answer names an account that is no longer a namesake - somebody renamed or removed it
        // between the two screens. Back to undecided rather than quietly picking another one.
        return [ClassImportAction::Decide, null, $candidates];
    }

    /**
     * §2.1: the header decides when it says so, otherwise the value does, and a value that names
     * both an option and a modality is refused with the remedy in the message.
     *
     * @return array{list<KnownValue>, list<ImportIssue>}
     */
    private function resolveValues(StudentRow $row, ClassImportContext $context, ?ExistingAccount $account): array
    {
        $values = [];
        $issues = [];

        foreach ($row->freeCells as $cell) {
            $folded = self::foldValue($cell->value);
            if ('' === $folded) {
                continue;
            }

            $wantsOption = str_contains($cell->foldedHeader, 'option');
            $wantsModality = str_contains($cell->foldedHeader, 'modalite');
            // A header naming both settles nothing; the value is left to speak for itself.
            $scoped = $wantsOption !== $wantsModality;

            $matches = [];
            foreach ($context->values() as $value) {
                if (!$value->matches($folded)) {
                    continue;
                }
                if ($scoped && $value->modality !== $wantsModality) {
                    continue;
                }
                $matches[] = $value;
            }

            if ([] === $matches) {
                $issues[] = ImportIssue::blocking('classImportIssueUnknownValueMessage', [
                    '%value%' => trim($cell->value),
                    '%program%' => $context->programLabel,
                    '%available%' => $context->valueLabels(),
                ]);
                continue;
            }

            if (\count($matches) > 1) {
                $issues[] = ImportIssue::blocking('classImportIssueAmbiguousValueMessage', [
                    '%value%' => trim($cell->value),
                    '%program%' => $context->programLabel,
                ]);
                continue;
            }

            $value = $matches[0];

            if (null !== $account && $account->carries($value)) {
                $issues[] = ImportIssue::warning('classImportWarningValueAlreadySetMessage', ['%value%' => $value->label]);
                continue;
            }

            foreach ($values as $already) {
                if ($already->id === $value->id && $already->modality === $value->modality) {
                    continue 2;
                }
            }

            $values[] = $value;
        }

        return [$values, $issues];
    }

    private function keyFor(StudentRow $row): NameKey
    {
        return NameKey::of($row->firstname, $row->lastname);
    }

    /**
     * Lines sharing the same non-empty key, each pointed at the others. Used for both of §4's
     * "twice in the file" refusals, which are the same rule read on two columns.
     *
     * @param list<StudentRow>            $rows
     * @param callable(StudentRow): string $keyOf
     *
     * @return array<int, list<int>>
     */
    private function duplicateLines(array $rows, callable $keyOf): array
    {
        $linesByKey = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            if ('' === $key || '|' === $key) {
                continue;
            }
            $linesByKey[$key][] = $row->line;
        }

        $duplicates = [];
        foreach ($linesByKey as $lines) {
            if (\count($lines) < 2) {
                continue;
            }
            foreach ($lines as $line) {
                $duplicates[$line] = array_values(array_filter($lines, static fn (int $other): bool => $other !== $line));
            }
        }

        return $duplicates;
    }

    /**
     * How a cell's value is compared to an option's name or short name: case, accents, spacing and
     * punctuation all dropped, so "SIO 2", "sio-2" and "SIO2" are one spelling. Deliberately harder
     * than the header fold, which keeps word boundaries because headers are read as words.
     */
    public static function foldValue(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', AsciiFolder::fold($value));
    }
}
