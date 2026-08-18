<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LdapManageUser;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\StudentImportBatch;
use App\Entity\User;
use App\Enum\StudentImportLineAction;
use App\Service\ClassImport\ClassImportAnalysis;
use App\Service\ClassImport\ClassImportAnalyzer;
use App\Service\ClassImport\ClassImportContextFactory;
use App\Service\ClassImport\ClassImportExecutor;
use App\Service\ClassImport\ClassImportNotExecutableException;
use App\Service\ClassImport\FreeCell;
use App\Service\ClassImport\StudentRow;
use App\Service\CsvTable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The writing half of the class import, against the real schema.
 *
 * Three things are worth a test more than the rest: that replaying the same file writes nothing the
 * second time (a secretariat uploads the same list twice as a matter of course), that a test class
 * queues nothing in the directory, and that an analysis which has stopped being importable is
 * refused rather than half-applied.
 */
class ClassImportExecutorTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private ClassImportExecutor $executor;
    private ClassImportAnalyzer $analyzer;
    private ClassImportContextFactory $contextFactory;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->executor = static::getContainer()->get(ClassImportExecutor::class);
        $this->analyzer = static::getContainer()->get(ClassImportAnalyzer::class);
        $this->contextFactory = static::getContainer()->get(ClassImportContextFactory::class);
        $this->operator = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'import.operator');
    }

    public function testCreatesTheAccountItsClassMembershipAndItsDirectoryRequest(): void
    {
        $program = $this->createProgram();
        $option = $this->addOption($program, 'Solutions logicielles', 'SLAM');

        $batch = $this->import($program, [$this->row(2, 'Delacroix', 'Ambre', 'ambre@example.org', 'SLAM')]);

        self::assertSame(1, $batch->getCreatedCount());
        self::assertCount(1, $batch->getLines());

        $line = $batch->getLines()->first();
        self::assertNotFalse($line);
        self::assertSame(StudentImportLineAction::Create, $line->getAction());

        $user = $line->getUser();
        self::assertNotNull($user);
        self::assertSame('adelacroix', $user->getUsername());
        self::assertSame('Ambre', $user->getFirstname());
        self::assertSame('ambre@example.org', $user->getContactEmail());
        self::assertTrue($user->isContactEmailVerified(), 'the address is trusted outright, no confirmation mail');
        self::assertTrue($program->getStudents()->contains($user));

        // The directory request is the only thing that reaches LDAP, and it is one row of a queue.
        $request = $line->getLdapRequest();
        self::assertInstanceOf(LdapManageUser::class, $request);
        self::assertSame('account_create', $request->getActionType());
        self::assertSame('student', $request->getUserType());
        self::assertSame('adelacroix', $request->getLogin());
        self::assertSame(0, $request->getState());

        self::assertCount(1, $this->optionLinks($program, $user));
        self::assertSame($option->getId(), $this->optionLinks($program, $user)[0]->getOption()?->getId());
    }

    // A secretariat uploads the same list twice as a matter of course; the second time must write
    // nothing at all and say so, rather than duplicating a class.
    public function testReplayingTheSameFileWritesNothing(): void
    {
        $program = $this->createProgram();
        $this->addOption($program, 'Solutions logicielles', 'SLAM');
        $rows = [$this->row(2, 'Delacroix', 'Ambre', 'ambre@example.org', 'SLAM')];

        $this->import($program, $rows);
        $this->entityManager->flush();

        $secondAnalysis = $this->analyze($program, $rows);

        self::assertFalse($secondAnalysis->isImportable(), 'a file that would change nothing is not an import');
        self::assertSame(0, $secondAnalysis->writingCount());
        self::assertSame(1, $secondAnalysis->updateCount());

        $this->expectException(ClassImportNotExecutableException::class);
        $this->executor->execute($secondAnalysis, $program, $this->operator, [], true);
    }

    public function testAnAnalysisThatIsNoLongerImportableIsRefusedWhole(): void
    {
        $program = $this->createProgram();
        $rows = [
            $this->row(2, 'Delacroix', 'Ambre', 'ambre@example.org'),
            $this->row(3, 'Ferreira', 'Lina', 'lina@example.org', 'SLM'),
        ];

        $analysis = $this->analyze($program, $rows);
        self::assertFalse($analysis->isImportable());

        try {
            $this->executor->execute($analysis, $program, $this->operator, [], true);
            self::fail('The import should have been refused.');
        } catch (ClassImportNotExecutableException) {
            // Not one account, not one queue row: the whole file or nothing.
            self::assertSame(0, $this->countUsersNamed('Delacroix'));
            self::assertCount(0, $program->getStudents());
        }
    }

    // A demonstration account has no Windows session to open and, reception being catch-all,
    // nothing to receive. The record is still a record, so the login is generated all the same.
    public function testATestClassQueuesNothingInTheDirectory(): void
    {
        $program = $this->createProgram();
        $program->setTestProgram(true);
        $this->entityManager->flush();

        $batch = $this->import($program, [$this->row(2, 'Delacroix', 'Ambre', 'ambre@example.org')]);

        $line = $batch->getLines()->first();
        self::assertNotFalse($line);
        self::assertNull($line->getLdapRequest(), 'no directory request for a demonstration account');
        self::assertNull($line->getDirectoryState(), 'and therefore no state to show, which is not "pending"');

        $user = $line->getUser();
        self::assertNotNull($user);
        self::assertTrue($user->isTestUser());
        self::assertSame('adelacroix', $user->getUsername(), 'the login is still generated and reserved');
        self::assertCount(0, $user->getEmailAliases(), 'and no School mail address either');
    }

    // "Martin Dupont" and "Marie Dupont" both fold to mdupont, and nothing is flushed while the
    // transaction is open - the database cannot answer for a login the same run just handed out.
    public function testTwoStudentsWhoseNamesFoldToTheSameLoginGetDistinctOnes(): void
    {
        $program = $this->createProgram();

        $batch = $this->import($program, [
            $this->row(2, 'Dupont', 'Martin', 'martin@example.org'),
            $this->row(3, 'Dupont', 'Marie', 'marie@example.org'),
        ]);

        $logins = [];
        foreach ($batch->getLines() as $line) {
            $logins[] = $line->getUser()?->getUsername();
        }

        self::assertSame(['mdupont', 'mdupont01'], $logins);
    }

    public function testAStudentAlreadyInTheClassOnlyGetsWhatWasMissing(): void
    {
        $program = $this->createProgram();
        $this->addOption($program, 'Solutions logicielles', 'SLAM');

        $existing = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'acarpin');
        $existing->setFirstname('Alice')->setLastname('Carpin');
        $program->addStudent($existing);
        $this->entityManager->flush();

        $batch = $this->import($program, [$this->row(2, 'CARPIN', 'alice', 'alice@example.org', 'SLAM')]);

        self::assertSame(1, $batch->getUpdatedCount());
        self::assertSame('alice@example.org', $existing->getContactEmail(), 'the missing address is filled in');
        self::assertCount(1, $this->optionLinks($program, $existing));

        $line = $batch->getLines()->first();
        self::assertNotFalse($line);
        self::assertNull($line->getLdapRequest(), 'the account already exists in the directory');
    }

    // --- helpers ---------------------------------------------------------------------------

    /** @param list<StudentRow> $rows */
    private function import(Program $program, array $rows): StudentImportBatch
    {
        $batch = $this->executor->execute($this->analyze($program, $rows), $program, $this->operator, [], true);
        $this->entityManager->flush();

        return $batch;
    }

    /** @param list<StudentRow> $rows */
    private function analyze(Program $program, array $rows): ClassImportAnalysis
    {
        return $this->analyzer->analyze($rows, $this->contextFactory->build($program, $rows), [], 'liste.csv');
    }

    private function row(int $line, string $lastname, string $firstname, string $email, ?string $option = null): StudentRow
    {
        return new StudentRow($line, $lastname, $firstname, $email, null === $option
            ? []
            : [new FreeCell('option', CsvTable::fold('option'), $option)]);
    }

    private function addOption(Program $program, string $name, string $shortName): Option
    {
        $option = new Option($name, $shortName, '#112233');
        $option->addProgram($program);
        $option->setCreatedBy($this->operator);
        $this->entityManager->persist($option);
        $this->entityManager->flush();

        return $option;
    }

    /** @return list<ProgramStudentOption> */
    private function optionLinks(Program $program, User $student): array
    {
        return $this->entityManager->getRepository(ProgramStudentOption::class)
            ->findBy(['program' => $program, 'student' => $student]);
    }

    private function countUsersNamed(string $lastname): int
    {
        return \count($this->entityManager->getRepository(User::class)->findBy(['lastname' => $lastname]));
    }
}
