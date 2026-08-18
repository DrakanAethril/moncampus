<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassImport;

use App\Enum\ClassImportAction;
use App\Service\ClassImport\AnalyzedStudent;
use App\Service\ClassImport\ClassImportAnalyzer;
use App\Service\ClassImport\ClassImportContext;
use App\Service\ClassImport\ExistingAccount;
use App\Service\ClassImport\FreeCell;
use App\Service\ClassImport\KnownValue;
use App\Service\ClassImport\NameKey;
use App\Service\ClassImport\NameNormalizer;
use App\Service\ClassImport\StudentRow;
use App\Service\CsvTable;
use PHPUnit\Framework\TestCase;

class ClassImportAnalyzerTest extends TestCase
{
    private ClassImportAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ClassImportAnalyzer(new NameNormalizer());
    }

    // --- verdicts (§3) ---------------------------------------------------------------------

    public function testAnUnknownNameIsACreation(): void
    {
        $student = $this->analyzeOne($this->row());

        self::assertSame(ClassImportAction::Create, $student->action);
        self::assertNull($student->account);
        self::assertSame([], $student->blockingIssues());
    }

    public function testANameAlreadyInTheClassIsAnUpdate(): void
    {
        $student = $this->analyzeOne(
            $this->row(freeCells: [$this->cell('option', 'SLAM')]),
            $this->context(accounts: [$this->account(inDestinationProgram: true)]),
        );

        self::assertSame(ClassImportAction::Update, $student->action);
        self::assertSame(12, $student->account?->id);
    }

    public function testANameKnownOutsideTheClassWaitsForADecision(): void
    {
        $student = $this->analyzeOne($this->row(), $this->context(accounts: [$this->account()]));

        self::assertSame(ClassImportAction::Decide, $student->action);
        self::assertCount(1, $student->candidates);
        self::assertTrue($student->isBlocking());
    }

    // Two people can really carry the same name; nothing is ever pre-selected for the operator.
    public function testTheDecisionIsNeverTakenForTheOperator(): void
    {
        $student = $this->analyzeOne($this->row(), $this->context(accounts: [$this->account()]));

        self::assertNull($student->account);
    }

    public function testAnsweringSamePersonAttachesTheAccount(): void
    {
        $student = $this->analyzeOne(
            $this->row(),
            $this->context(accounts: [$this->account()]),
            decisions: [2 => 12],
        );

        self::assertSame(ClassImportAction::Attach, $student->action);
        self::assertSame(12, $student->account?->id);
        self::assertSame([], $student->blockingIssues());
    }

    public function testAnsweringNamesakeCreatesAFreshAccount(): void
    {
        $student = $this->analyzeOne(
            $this->row(),
            $this->context(accounts: [$this->account()]),
            decisions: [2 => null],
        );

        self::assertSame(ClassImportAction::Create, $student->action);
        self::assertNull($student->account);
        self::assertSame([], $student->blockingIssues());
    }

    public function testADecisionNamingAnAccountThatIsNoLongerACandidateIsIgnored(): void
    {
        $student = $this->analyzeOne(
            $this->row(),
            $this->context(accounts: [$this->account()]),
            decisions: [2 => 999],
        );

        self::assertSame(ClassImportAction::Decide, $student->action);
    }

    public function testSeveralNamesakesAreAllOffered(): void
    {
        $context = $this->context(accounts: [
            $this->account(id: 12, login: 'mdupont', programLabel: 'MCO 1'),
            $this->account(id: 13, login: 'mdupont01', programLabel: 'SIO 1'),
        ]);

        $student = $this->analyzeOne($this->row(), $context);

        self::assertCount(2, $student->candidates);
        self::assertSame(ClassImportAction::Decide, $student->action);
    }

    public function testNamesAreMatchedWhateverTheSpelling(): void
    {
        $context = $this->context(accounts: [$this->account(firstname: 'Jean-Baptiste', lastname: 'Bachir-Bey', inDestinationProgram: true)]);

        $student = $this->analyzeOne($this->row(firstname: 'jean baptiste', lastname: 'BACHIR BEY'), $context);

        self::assertSame(ClassImportAction::Update, $student->action);
    }

    // --- names (§2.2) ----------------------------------------------------------------------

    public function testNamesAreNormalisedAndTheChangeIsFlagged(): void
    {
        $student = $this->analyzeOne($this->row(firstname: 'martin', lastname: 'GOUBAULT DE BRUGIERE'));

        self::assertSame('Martin', $student->firstname);
        self::assertSame('Goubault de Brugiere', $student->lastname);
        self::assertTrue($student->nameWasNormalized());
        self::assertSame(['classImportNoteNameNormalizedMessage'], $this->keys($student->warnings()));
    }

    public function testASpellingThatSaysSomethingIsLeftAlone(): void
    {
        $student = $this->analyzeOne($this->row(firstname: 'Martin', lastname: 'MacLeod'));

        self::assertSame('MacLeod', $student->lastname);
        self::assertFalse($student->nameWasNormalized());
    }

    // --- free columns (§2.1) ---------------------------------------------------------------

    public function testAValueIsResolvedAgainstTheClassOptionsAndModalities(): void
    {
        $student = $this->analyzeOne($this->row(freeCells: [
            $this->cell('option', 'SLAM'),
            $this->cell('modalite', 'Alternance'),
        ]));

        self::assertSame(['SLAM', 'Alternance'], array_map(static fn (KnownValue $v): string => $v->label, $student->valuesToAdd));
    }

    public function testTheHeaderDecidesWhenItSaysSo(): void
    {
        // "Alternance" is both an option and a modality here; the header settles it.
        $context = $this->context(
            options: [new KnownValue(1, 'Alternance', false, ['alternance'])],
            modalities: [new KnownValue(2, 'Alternance', true, ['alternance'])],
        );

        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('modalite', 'Alternance')]), $context);

        self::assertSame([], $student->blockingIssues());
        self::assertTrue($student->valuesToAdd[0]->modality);
    }

    public function testAValueThatIsBothAnOptionAndAModalityBlocks(): void
    {
        $context = $this->context(
            options: [new KnownValue(1, 'Alternance', false, ['alternance'])],
            modalities: [new KnownValue(2, 'Alternance', true, ['alternance'])],
        );

        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('', 'Alternance')]), $context);

        self::assertSame(['classImportIssueAmbiguousValueMessage'], $this->keys($student->blockingIssues()));
    }

    public function testAnUnknownValueBlocksAndSaysWhatIsAvailable(): void
    {
        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('option', 'SLM')]));

        $issue = $student->blockingIssues()[0];
        self::assertSame('classImportIssueUnknownValueMessage', $issue->messageKey);
        self::assertSame('SLM', $issue->parameters['%value%']);
        self::assertSame('SISR, SLAM, Initial, Alternance', $issue->parameters['%available%']);
    }

    public function testAValueIsMatchedOnItsShortNameAndIgnoresCaseSpacingAndAccents(): void
    {
        $context = $this->context(options: [new KnownValue(1, 'Systèmes et réseaux', false, ['systemesetreseaux', 'sisr'])]);

        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('option', ' SISR ')]), $context);

        self::assertSame('Systèmes et réseaux', $student->valuesToAdd[0]->label);
    }

    public function testAnEmptyFreeCellSaysNothing(): void
    {
        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('option', '')]));

        self::assertSame([], $student->valuesToAdd);
        self::assertSame([], $student->blockingIssues());
    }

    public function testAValueTheStudentAlreadyCarriesIsNotAddedTwice(): void
    {
        $context = $this->context(accounts: [$this->account(inDestinationProgram: true, optionIds: [2])]);

        $student = $this->analyzeOne($this->row(freeCells: [$this->cell('option', 'SLAM')]), $context);

        self::assertSame([], $student->valuesToAdd);
        self::assertSame(['classImportWarningValueAlreadySetMessage'], $this->keys($student->warnings()));
    }

    // --- blocking (§4) ---------------------------------------------------------------------

    public function testAMissingFirstnameBlocks(): void
    {
        $student = $this->analyzeOne($this->row(firstname: ''));

        self::assertSame(['classImportIssueMissingFirstnameMessage'], $this->keys($student->blockingIssues()));
        self::assertSame(ClassImportAction::Blocked, $student->action);
    }

    public function testAMissingLastnameBlocks(): void
    {
        $student = $this->analyzeOne($this->row(lastname: ''));

        self::assertSame(['classImportIssueMissingLastnameMessage'], $this->keys($student->blockingIssues()));
    }

    public function testTwoLinesNamingTheSamePersonBlockBoth(): void
    {
        $analysis = $this->analyzer->analyze(
            [$this->row(line: 4), $this->row(line: 19)],
            $this->context(),
            [],
        );

        self::assertSame('classImportIssueDuplicateInFileMessage', $analysis->students[0]->blockingIssues()[0]->messageKey);
        self::assertSame('19', $analysis->students[0]->blockingIssues()[0]->parameters['%otherLine%']);
        self::assertSame('4', $analysis->students[1]->blockingIssues()[0]->parameters['%otherLine%']);
    }

    public function testAnInvalidAddressBlocks(): void
    {
        $student = $this->analyzeOne($this->row(email: 'martin(at)example.org'));

        self::assertSame(['classImportIssueInvalidEmailMessage'], $this->keys($student->blockingIssues()));
    }

    public function testTwoLinesSharingAnAddressBlock(): void
    {
        $analysis = $this->analyzer->analyze(
            [$this->row(line: 4, email: 'famille@example.org'), $this->row(line: 5, firstname: 'Alice', email: 'famille@example.org')],
            $this->context(),
            [],
        );

        self::assertSame('classImportIssueDuplicateEmailInFileMessage', $analysis->students[0]->blockingIssues()[0]->messageKey);
        self::assertSame('classImportIssueDuplicateEmailInFileMessage', $analysis->students[1]->blockingIssues()[0]->messageKey);
    }

    // A secretary putting a parent's address on two siblings must be told which account holds it,
    // or the refusal is incomprehensible.
    public function testAnAddressAlreadyHeldByAnotherAccountBlocksAndNamesIt(): void
    {
        $context = $this->context(emailOwners: ['martin@example.org' => [99, 'smartin (MCO 1)']]);

        $issue = $this->analyzeOne($this->row(), $context)->blockingIssues()[0];

        self::assertSame('classImportIssueEmailTakenMessage', $issue->messageKey);
        self::assertSame('smartin (MCO 1)', $issue->parameters['%owner%']);
    }

    public function testAnAddressHeldByTheAccountOfTheLineItselfIsNotAClash(): void
    {
        $context = $this->context(
            accounts: [$this->account(inDestinationProgram: true, contactEmail: 'martin@example.org')],
            emailOwners: ['martin@example.org' => [12, 'mdupont (SIO 2)']],
        );

        self::assertSame([], $this->analyzeOne($this->row(), $context)->blockingIssues());
    }

    public function testAMatchedAccountThatIsNotAStudentBlocks(): void
    {
        $context = $this->context(accounts: [$this->account(userType: 'teacher', inDestinationProgram: true)]);

        $issue = $this->analyzeOne($this->row(), $context)->blockingIssues()[0];

        self::assertSame('classImportIssueNotAStudentMessage', $issue->messageKey);
        self::assertSame('accountTypeTeacherLabel', $issue->keyParameters['%type%']);
    }

    public function testAFileWhereNothingWouldBeWrittenIsNotAnImport(): void
    {
        $context = $this->context(accounts: [$this->account(inDestinationProgram: true, contactEmail: 'martin@example.org')]);

        $analysis = $this->analyzer->analyze([$this->row()], $context, []);

        self::assertFalse($analysis->isImportable());
        self::assertSame(0, $analysis->writingCount());
    }

    public function testAFileThatAddsSomethingIsImportable(): void
    {
        $analysis = $this->analyzer->analyze([$this->row()], $this->context(), []);

        self::assertTrue($analysis->isImportable());
        self::assertSame(1, $analysis->createCount());
    }

    public function testOneBlockingLineRefusesTheWholeFile(): void
    {
        $analysis = $this->analyzer->analyze(
            [$this->row(line: 2), $this->row(line: 3, firstname: 'Alice', lastname: '', email: 'alice@example.org')],
            $this->context(),
            [],
        );

        self::assertFalse($analysis->isImportable());
        self::assertSame(1, $analysis->blockingCount());
    }

    // --- warnings (§4) ---------------------------------------------------------------------

    public function testAnEmptyAddressIsOnlyAWarning(): void
    {
        $student = $this->analyzeOne($this->row(email: ''));

        self::assertSame(['classImportWarningNoEmailMessage'], $this->keys($student->warnings()));
        self::assertSame(ClassImportAction::Create, $student->action);
    }

    public function testAnExistingAddressIsKeptAndTheFileOneIgnored(): void
    {
        $context = $this->context(accounts: [$this->account(inDestinationProgram: true, contactEmail: 'other@example.org')]);

        $student = $this->analyzeOne($this->row(), $context);

        self::assertSame(['classImportWarningEmailKeptMessage'], $this->keys($student->warnings()));
        self::assertFalse($student->fillsEmail);
    }

    public function testAMissingAddressOnAnExistingAccountIsFilledIn(): void
    {
        $context = $this->context(accounts: [$this->account(inDestinationProgram: true)]);

        $student = $this->analyzeOne($this->row(), $context);

        self::assertTrue($student->fillsEmail);
        self::assertSame([], $this->keys($student->warnings()));
    }

    // The reactivation is announced before it happens, never after.
    public function testAttachingADisabledAccountWarnsThatItWillBeReactivated(): void
    {
        $student = $this->analyzeOne(
            $this->row(),
            $this->context(accounts: [$this->account(active: false)]),
            decisions: [2 => 12],
        );

        self::assertContains('classImportWarningReactivationMessage', $this->keys($student->warnings()));
        self::assertSame(ClassImportAction::Attach, $student->action);
    }

    public function testAStudentOfAnotherClassOfTheSameYearIsFlagged(): void
    {
        $student = $this->analyzeOne(
            $this->row(),
            $this->context(accounts: [$this->account(inAnotherProgramOfTheSameYear: true)]),
            decisions: [2 => 12],
        );

        self::assertContains('classImportWarningTwoProgramsMessage', $this->keys($student->warnings()));
    }

    // --- aggregates ------------------------------------------------------------------------

    public function testTheAnalysisCountsWhatTheOperatorValidatesAgainst(): void
    {
        $context = $this->context(accounts: [
            $this->account(id: 12, firstname: 'Alice', lastname: 'Durand', inDestinationProgram: true),
            $this->account(id: 13, firstname: 'Zoe', lastname: 'Muller'),
        ]);

        $analysis = $this->analyzer->analyze([
            $this->row(line: 2),
            $this->row(line: 3, firstname: 'Alice', lastname: 'Durand', email: 'alice@example.org'),
            $this->row(line: 4, firstname: 'Zoe', lastname: 'Muller', email: 'zoe@example.org'),
        ], $context, [4 => 13]);

        self::assertSame(1, $analysis->createCount());
        self::assertSame(1, $analysis->attachCount());
        self::assertSame(1, $analysis->updateCount());
        self::assertSame(0, $analysis->decideCount());
        self::assertTrue($analysis->isImportable());
    }

    public function testTheAnalysisReportsHowManyDecisionsAreStillOpen(): void
    {
        $context = $this->context(accounts: [$this->account()]);

        $analysis = $this->analyzer->analyze([$this->row()], $context, []);

        self::assertSame(1, $analysis->decideCount());
        self::assertFalse($analysis->isImportable());
    }

    // A single active namesake is the case a whole promotion moving up a year produces; the bulk
    // action must not reach anything else.
    public function testTheRowsTheBulkActionMayTouchAreListed(): void
    {
        $context = $this->context(accounts: [
            $this->account(id: 12, firstname: 'Alice', lastname: 'Durand'),
            $this->account(id: 13, firstname: 'Zoe', lastname: 'Muller', active: false),
            $this->account(id: 14, firstname: 'Leo', lastname: 'Petit'),
            $this->account(id: 15, firstname: 'Leo', lastname: 'Petit', login: 'lpetit01'),
        ]);

        $analysis = $this->analyzer->analyze([
            $this->row(line: 2, firstname: 'Alice', lastname: 'Durand', email: 'a@example.org'),
            $this->row(line: 3, firstname: 'Zoe', lastname: 'Muller', email: 'z@example.org'),
            $this->row(line: 4, firstname: 'Leo', lastname: 'Petit', email: 'l@example.org'),
        ], $context, []);

        self::assertSame([2 => 12], $analysis->obviousDecisions());
    }

    // --- helpers ---------------------------------------------------------------------------

    /** @param array<int, int|null> $decisions */
    private function analyzeOne(StudentRow $row, ?ClassImportContext $context = null, array $decisions = []): AnalyzedStudent
    {
        return $this->analyzer->analyze([$row], $context ?? $this->context(), $decisions)->students[0];
    }

    /** @param list<\App\Service\ClassImport\ImportIssue> $issues
     * @return list<string> */
    private function keys(array $issues): array
    {
        return array_map(static fn (\App\Service\ClassImport\ImportIssue $issue): string => $issue->messageKey, $issues);
    }

    /** @param list<FreeCell> $freeCells */
    private function row(
        int $line = 2,
        string $firstname = 'Martin',
        string $lastname = 'Dupont',
        string $email = 'martin@example.org',
        array $freeCells = [],
    ): StudentRow {
        return new StudentRow($line, $lastname, $firstname, $email, $freeCells);
    }

    private function cell(string $header, string $value): FreeCell
    {
        return new FreeCell($header, CsvTable::fold($header), $value);
    }

    /**
     * @param list<KnownValue>                    $options
     * @param list<KnownValue>                    $modalities
     * @param list<ExistingAccount>               $accounts
     * @param array<string, array{int, string}>   $emailOwners
     */
    private function context(
        ?array $options = null,
        ?array $modalities = null,
        array $accounts = [],
        array $emailOwners = [],
    ): ClassImportContext {
        $accountsByName = [];
        foreach ($accounts as $account) {
            $accountsByName[NameKey::of($account->firstname, $account->lastname)->value][] = $account;
        }

        return new ClassImportContext(
            programLabel: 'SIO 2',
            testProgram: false,
            options: $options ?? [
                new KnownValue(1, 'SISR', false, ['sisr']),
                new KnownValue(2, 'SLAM', false, ['slam']),
            ],
            modalities: $modalities ?? [
                new KnownValue(3, 'Initial', true, ['initial']),
                new KnownValue(4, 'Alternance', true, ['alternance']),
            ],
            accountsByNameKey: $accountsByName,
            emailOwners: $emailOwners,
        );
    }

    /**
     * @param list<int> $optionIds
     * @param list<int> $modalityIds
     */
    private function account(
        int $id = 12,
        string $login = 'mdupont',
        string $firstname = 'Martin',
        string $lastname = 'Dupont',
        string $userType = 'student',
        bool $active = true,
        ?string $contactEmail = null,
        bool $inDestinationProgram = false,
        bool $inAnotherProgramOfTheSameYear = false,
        string $programLabel = 'MCO 1',
        array $optionIds = [],
        array $modalityIds = [],
    ): ExistingAccount {
        return new ExistingAccount(
            id: $id,
            login: $login,
            firstname: $firstname,
            lastname: $lastname,
            userType: $userType,
            active: $active,
            contactEmail: $contactEmail,
            inDestinationProgram: $inDestinationProgram,
            inAnotherProgramOfTheSameYear: $inAnotherProgramOfTheSameYear,
            programLabel: $programLabel,
            createdAt: new \DateTimeImmutable('2025-09-01'),
            optionIds: $optionIds,
            modalityIds: $modalityIds,
        );
    }
}
