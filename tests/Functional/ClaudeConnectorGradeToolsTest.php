<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Enum\RubricSectionKind;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grades through the Claude connector: the class and what was entered are read back, and marks are
 * written as on the entry screen - question by question when the evaluation has a barème, one cell
 * per student otherwise. A send is refused whole at its first wrong value, erases nothing, and
 * never rewrites a mark already there unless told to.
 */
class ClaudeConnectorGradeToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    private EntityManagerInterface $entityManager;
    private User $teacher;
    private User $aubert;
    private User $zola;
    private Topic $topic;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'grades.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'grades.teacher');
        $this->zola = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'grades.zola');
        $this->zola->setFirstname('Émile');
        $this->zola->setLastname('Zola');
        $this->aubert = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'grades.aubert');
        $this->aubert->setFirstname('Zoé');
        $this->aubert->setLastname('Aubert');
        $this->program = $this->createProgram([$this->zola, $this->aubert], [$this->teacher], $admin);
        $this->program->setVisibility(VisibilityLevel::Everyone);

        $group = new TopicGroup('Groupe', $this->program);
        $group->setCreatedBy($admin);
        $this->topic = new Topic('Réseaux', $this->program, $group);
        $this->topic->setCreatedBy($admin);
        $this->topic->addTeacher($this->teacher);
        $this->entityManager->persist($group);
        $this->entityManager->persist($this->topic);
        $this->entityManager->flush();
    }

    public function testTheClassIsReadBySurnameWithTheQuestionsOfTheBareme(): void
    {
        [$evaluation, $first, $second, $malus] = $this->rubricEvaluation();

        $read = $this->callTool($this->accessTokenFor($this->teacher), 'grades_get', ['evaluationId' => $evaluation->getId()]);

        self::assertFalse($read['isError'], $read['text']);
        self::assertTrue($read['data']['hasRubric']);
        self::assertTrue($read['data']['canEdit']);
        self::assertSame(
            [
                ['studentId' => $this->aubert->getId(), 'name' => 'Aubert Zoé'],
                ['studentId' => $this->zola->getId(), 'name' => 'Zola Émile'],
            ],
            array_map(static fn (array $student): array => ['studentId' => $student['studentId'], 'name' => $student['name']], $this->rows($read['data'], 'students')),
        );
        self::assertSame(
            [
                ['questionId' => $first->getId(), 'part' => 'Partie 1', 'kind' => 'standard', 'label' => '1a', 'maxPoints' => 4.0],
                ['questionId' => $second->getId(), 'part' => 'Partie 1', 'kind' => 'standard', 'label' => '1b', 'maxPoints' => 6.0],
                ['questionId' => $malus->getId(), 'part' => null, 'kind' => 'malus', 'label' => 'Orthographe', 'maxPoints' => 1.0],
            ],
            $this->normalized($this->rows($read['data'], 'questions')),
        );
    }

    public function testPointsAreEnteredQuestionByQuestionAndTheTotalFollows(): void
    {
        [$evaluation, $first, $second, $malus] = $this->rubricEvaluation();
        $token = $this->accessTokenFor($this->teacher);

        $written = $this->callTool($token, 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [
                ['studentId' => $this->aubert->getId(), 'answers' => [
                    ['questionId' => $first->getId(), 'points' => 3],
                    ['questionId' => $second->getId(), 'points' => '5,5'],
                    ['questionId' => $malus->getId(), 'points' => 1],
                ]],
                ['studentId' => $this->zola->getId(), 'answers' => [
                    ['questionId' => $first->getId(), 'points' => 'nt'],
                ]],
            ],
        ]);

        self::assertFalse($written['isError'], $written['text']);
        self::assertSame(2, $written['data']['written']);

        $this->entityManager->clear();
        $grade = $this->gradeOf($evaluation, $this->aubert);
        self::assertInstanceOf(Grade::class, $grade);
        self::assertSame(7.5, $grade->getValue());
        self::assertSame($this->teacher->getId(), $grade->getGradedBy()?->getId());
        self::assertCount(3, $grade->getRubricAnswers());
        self::assertSame(0.0, $this->gradeOf($evaluation, $this->zola)?->getValue());

        $read = $this->callTool($token, 'grades_get', ['evaluationId' => $evaluation->getId()]);
        $aubert = $this->rows($read['data'], 'students')[0];
        self::assertEquals(7.5, $aubert['value']);
        self::assertEquals(
            [['questionId' => $first->getId(), 'points' => 3], ['questionId' => $second->getId(), 'points' => 5.5], ['questionId' => $malus->getId(), 'points' => 1]],
            $aubert['answers'],
        );
        self::assertEquals([['questionId' => $first->getId(), 'points' => 'nt']], $this->rows($read['data'], 'students')[1]['answers']);
    }

    public function testWithoutBaremeOneCellPerStudentIsEnteredAsOnTheGrid(): void
    {
        $evaluation = $this->plainEvaluation();
        $token = $this->accessTokenFor($this->teacher);

        $written = $this->callTool($token, 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [
                ['studentId' => $this->aubert->getId(), 'grade' => '14,5'],
                ['studentId' => $this->zola->getId(), 'grade' => 'abs'],
            ],
        ]);

        self::assertFalse($written['isError'], $written['text']);
        $this->entityManager->clear();
        self::assertSame(14.5, $this->gradeOf($evaluation, $this->aubert)?->getValue());
        self::assertSame(GradeStatus::Absent, $this->gradeOf($evaluation, $this->zola)?->getStatus());

        $read = $this->callTool($token, 'grades_get', ['evaluationId' => $evaluation->getId()]);
        self::assertSame(['14,5', 'abs'], array_column($this->rows($read['data'], 'students'), 'grade'));
        self::assertEquals(14.5, $read['data']['classAverage']);
    }

    /** The connector's rule for every document: refused whole, so the class is never half entered. */
    public function testOneWrongValueRefusesTheWholeSend(): void
    {
        [$evaluation, $first, $second] = $this->rubricEvaluation();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [
                ['studentId' => $this->aubert->getId(), 'answers' => [['questionId' => $first->getId(), 'points' => 3]]],
                ['studentId' => $this->zola->getId(), 'answers' => [['questionId' => $second->getId(), 'points' => 7]]],
            ],
        ]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Zola Émile', $result['text']);
        self::assertStringContainsString('dépasse', $result['text']);
        self::assertSame([], $this->entityManager->getRepository(Grade::class)->findBy(['evaluation' => $evaluation]));
    }

    public function testAMarkAlreadyThereIsNotRewrittenUnlessTold(): void
    {
        $evaluation = $this->plainEvaluation();
        $token = $this->accessTokenFor($this->teacher);
        $send = fn (string $grade, bool $replace = false): array => $this->callTool($token, 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [['studentId' => $this->aubert->getId(), 'grade' => $grade]],
            'replace' => $replace,
        ]);

        self::assertFalse($send('12')['isError']);

        // The same mark again is nothing to refuse, and nothing to write.
        $again = $send('12');
        self::assertSame(0, $again['data']['written'] ?? null, $again['text']);
        self::assertSame(1, $again['data']['unchangedValues'] ?? null);

        $refused = $send('15');
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('replace', $refused['text']);
        $this->entityManager->clear();
        self::assertSame(12.0, $this->gradeOf($evaluation, $this->aubert)?->getValue());

        self::assertFalse($send('15', true)['isError']);
        $this->entityManager->clear();
        self::assertSame(15.0, $this->gradeOf($evaluation, $this->aubert)?->getValue());
    }

    /** @return iterable<string, array{bool, array<string, mixed>, string}> */
    public static function refusedRowProvider(): iterable
    {
        yield 'an overall grade on an evaluation with a barème' => [true, ['grade' => '12'], 'barème'];
        yield 'points per question without a barème' => [false, ['answers' => [['questionId' => 1, 'points' => 2]]], 'barème'];
        // The grid clamps 25/20 down to 20; from Claude it is a misreading, and is said.
        yield 'a grade above what the evaluation is marked out of' => [false, ['grade' => '25'], 'dépasse'];
        yield 'something that is not a grade' => [false, ['grade' => 'bien'], 'n\'est pas une note'];
        // Nothing is deleted through the connector: an empty cell is the screen's gesture.
        yield 'an empty grade' => [false, ['grade' => ''], 'efface'];
        yield 'a question of another barème' => [true, ['answers' => [['questionId' => 999999, 'points' => 1]]], 'question'];
    }

    /** @param array<string, mixed> $row */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRowProvider')]
    public function testARowThatSaysNothingUsableIsRefused(bool $withRubric, array $row, string $expected): void
    {
        $evaluation = $withRubric ? $this->rubricEvaluation()[0] : $this->plainEvaluation();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [['studentId' => $this->aubert->getId(), ...$row]],
        ]);

        self::assertTrue($result['isError'], $result['text']);
        self::assertStringContainsString($expected, $result['text']);
        self::assertSame([], $this->entityManager->getRepository(Grade::class)->findBy(['evaluation' => $evaluation]));
    }

    public function testAStudentOfAnotherClassIsRefused(): void
    {
        $evaluation = $this->plainEvaluation();
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'grades.outsider');

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [['studentId' => $outsider->getId(), 'grade' => '12']],
        ]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('pas un étudiant de cette classe', $result['text']);
    }

    /** Reading is shared by the matière's titulaires; writing is the author's alone. */
    public function testACoTitulaireReadsTheColleaguesGradesAndWritesNone(): void
    {
        $evaluation = $this->plainEvaluation();
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'grades.colleague');
        $this->program->addTeacher($colleague);
        $this->topic->addTeacher($colleague);
        $this->entityManager->flush();
        $token = $this->accessTokenFor($colleague);

        $read = $this->callTool($token, 'grades_get', ['evaluationId' => $evaluation->getId()]);
        self::assertFalse($read['isError'], $read['text']);
        self::assertFalse($read['data']['canEdit']);

        $written = $this->callTool($token, 'grades_set', [
            'evaluationId' => $evaluation->getId(),
            'grades' => [['studentId' => $this->aubert->getId(), 'grade' => '12']],
        ]);
        self::assertTrue($written['isError']);
        self::assertStringContainsString('introuvable', $written['text']);
    }

    /** VIEW lets an enrolled student through; the class's marks never do. */
    public function testAStudentNeverReadsTheClass(): void
    {
        $evaluation = $this->plainEvaluation();

        $result = $this->callTool($this->accessTokenFor($this->aubert), 'grades_get', ['evaluationId' => $evaluation->getId()]);

        self::assertTrue($result['isError']);
    }

    /** @return array{Evaluation, EvaluationRubricQuestion, EvaluationRubricQuestion, EvaluationRubricQuestion} */
    private function rubricEvaluation(): array
    {
        $evaluation = new Evaluation($this->topic, 'DS VLAN', new \DateTimeImmutable('today'));
        $evaluation->setCreatedBy($this->teacher);
        $evaluation->setScale(10.0);
        $section = new EvaluationRubricSection('Partie 1', 0, RubricSectionKind::Standard);
        $section->addQuestion($first = new EvaluationRubricQuestion('1a', 4.0, 0));
        $section->addQuestion($second = new EvaluationRubricQuestion('1b', 6.0, 1));
        $band = new EvaluationRubricSection('', 1, RubricSectionKind::Malus);
        $band->addQuestion($malus = new EvaluationRubricQuestion('Orthographe', 1.0, 0));
        $evaluation->addRubricSection($section);
        $evaluation->addRubricSection($band);
        foreach ([$evaluation, $section, $band] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$evaluation, $first, $second, $malus];
    }

    private function plainEvaluation(): Evaluation
    {
        $evaluation = new Evaluation($this->topic, 'Interrogation', new \DateTimeImmutable('today'));
        $evaluation->setCreatedBy($this->teacher);
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        return $evaluation;
    }

    private function gradeOf(Evaluation $evaluation, User $student): ?Grade
    {
        return $this->entityManager->getRepository(Grade::class)->findOneBy(['evaluation' => $evaluation->getId(), 'student' => $student->getId()]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? null;
        self::assertIsArray($rows);
        $typed = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            /* @var array<string, mixed> $row */
            $typed[] = $row;
        }

        return $typed;
    }

    /**
     * JSON has one number type: 4.0 comes back as 4.
     *
     * @param list<array<string, mixed>> $questions
     *
     * @return list<array<string, mixed>>
     */
    private function normalized(array $questions): array
    {
        return array_map(static function (array $question): array {
            $max = $question['maxPoints'] ?? null;
            $question['maxPoints'] = is_numeric($max) ? (float) $max : null;

            return $question;
        }, $questions);
    }
}
