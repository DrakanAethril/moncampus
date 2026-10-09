<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\GradeRubricAnswer;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\RubricSectionKind;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The carnet de notes through the Claude connector: an evaluation and its barème created in a
 * matière the teacher holds, never visible before the teacher could look, and a barème never
 * rebuilt once points were entered against it.
 */
class ClaudeConnectorGradebookToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    private const array RUBRIC = [
        'format' => 'moncampus-bareme/1',
        'sections' => [
            ['name' => 'Partie 1 - Adressage', 'questions' => [['label' => '1a', 'maxPoints' => 4], ['label' => '1b', 'maxPoints' => 6]]],
            ['name' => 'Partie 2 - VLAN', 'questions' => [['label' => '2a', 'maxPoints' => 10]]],
        ],
        'malus' => [['label' => 'Orthographe', 'maxPoints' => 1]],
    ];

    private EntityManagerInterface $entityManager;
    private User $teacher;
    private User $student;
    private Topic $topic;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'claude.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eleve.claude');
        $this->program = $this->createProgram([$this->student], [$this->teacher], $admin);
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

    public function testAnEvaluationIsCreatedWithItsBaremeAndHiddenForADay(): void
    {
        $token = $this->accessTokenFor($this->teacher);

        $overview = $this->callTool($token, 'gradebook_overview');
        self::assertStringContainsString('Réseaux', (string) json_encode($overview['data'], \JSON_UNESCAPED_UNICODE));

        $created = $this->callTool($token, 'evaluation_create', [
            'topicId' => $this->topic->getId(),
            'name' => 'DS VLAN',
            'date' => '2026-10-15',
            'rubric' => self::RUBRIC,
        ]);
        self::assertFalse($created['isError'], $created['text']);

        $evaluation = $this->entityManager->find(Evaluation::class, $created['data']['evaluationId']);
        self::assertInstanceOf(Evaluation::class, $evaluation);
        self::assertSame($this->teacher->getId(), $evaluation->getCreatedBy()?->getId());
        self::assertFalse($evaluation->isVisibleAt(new \DateTimeImmutable('+23 hours')));
        self::assertTrue($evaluation->isVisibleAt(new \DateTimeImmutable('+25 hours')));
        self::assertSame(20.0, $evaluation->getRubricReferencePoints());
        self::assertSame(['Partie 1 - Adressage', 'Partie 2 - VLAN'], array_map(static fn ($section) => $section->getName(), $evaluation->getStandardRubricSections()));
        self::assertNotNull($evaluation->getRubricSectionOfKind(RubricSectionKind::Malus));

        $read = $this->callTool($token, 'rubric_get', ['evaluationId' => $evaluation->getId()]);
        $document = $read['data']['document'];
        self::assertIsArray($document);
        self::assertEquals([['label' => 'Orthographe', 'maxPoints' => 1]], $document['malus']);
    }

    public function testVisibilityInThePastIsRefused(): void
    {
        $result = $this->callTool($this->accessTokenFor($this->teacher), 'evaluation_create', [
            'topicId' => $this->topic->getId(),
            'name' => 'DS',
            'date' => '2026-10-15',
            'visibleAt' => '2020-01-01T08:00',
        ]);

        self::assertTrue($result['isError']);
        self::assertSame([], $this->entityManager->getRepository(Evaluation::class)->findBy(['topic' => $this->topic]));
    }

    public function testARefusedBaremeLeavesNoEvaluationBehind(): void
    {
        $result = $this->callTool($this->accessTokenFor($this->teacher), 'evaluation_create', [
            'topicId' => $this->topic->getId(),
            'name' => 'DS',
            'date' => '2026-10-15',
            'rubric' => ['format' => 'moncampus-bareme/1', 'sections' => [['name' => 'Partie 1', 'questions' => [['label' => 'Une question beaucoup trop longue', 'maxPoints' => 2]]]]],
        ]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('dépasse 20 caractères', $result['text']);
        self::assertSame([], $this->entityManager->getRepository(Evaluation::class)->findBy(['topic' => $this->topic]));
    }

    public function testABaremeWithPointsEnteredIsNotReplaced(): void
    {
        $evaluation = new Evaluation($this->topic, 'DS noté', new \DateTimeImmutable('today'));
        $evaluation->setCreatedBy($this->teacher);
        $section = new EvaluationRubricSection('Partie 1', 0, RubricSectionKind::Standard);
        $question = new EvaluationRubricQuestion('1a', 20.0, 0);
        $section->addQuestion($question);
        $evaluation->addRubricSection($section);
        $grade = new Grade($evaluation, $this->student);
        $grade->addRubricAnswer((new GradeRubricAnswer($grade, $question))->setPointsAwarded(12.0));
        foreach ([$evaluation, $section, $grade] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'rubric_set', ['evaluationId' => $evaluation->getId(), 'document' => self::RUBRIC]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('points ont déjà été saisis', $result['text']);
    }

    public function testABaremeIsCopiedToAnotherEvaluation(): void
    {
        $token = $this->accessTokenFor($this->teacher);
        $source = $this->callTool($token, 'evaluation_create', ['topicId' => $this->topic->getId(), 'name' => 'DS 1', 'date' => '2026-10-15', 'rubric' => self::RUBRIC]);
        $target = $this->callTool($token, 'evaluation_create', ['topicId' => $this->topic->getId(), 'name' => 'DS 1 bis', 'date' => '2026-10-22', 'scale' => 22]);

        $copied = $this->callTool($token, 'rubric_copy', ['fromEvaluationId' => $source['data']['evaluationId'], 'toEvaluationId' => $target['data']['evaluationId']]);

        self::assertFalse($copied['isError'], $copied['text']);
        // A barème on 20 for an evaluation on 22 is legal, and said.
        $remark = $copied['data']['remark'] ?? null;
        self::assertIsString($remark);
        self::assertStringContainsString('diffère', $remark);
    }

    public function testAMatiereTheTeacherDoesNotHoldIsNotFound(): void
    {
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.colleague');
        $this->program->addTeacher($colleague);
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($colleague), 'evaluation_create', ['topicId' => $this->topic->getId(), 'name' => 'DS', 'date' => '2026-10-15']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('introuvable', $result['text']);
    }

    /** An update names its fields and writes no other - the connector's rule for every update. */
    public function testAnUpdateWritesTheFieldsItNamesAndNoOther(): void
    {
        $evaluation = $this->evaluation();
        $visibleAt = $evaluation->getVisibleAt();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'evaluation_update', [
            'evaluationId' => $evaluation->getId(),
            'name' => 'DS VLAN (rattrapage)',
            'coefficient' => 2,
        ]);

        self::assertFalse($result['isError'], $result['text']);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Evaluation::class, $evaluation->getId());
        self::assertInstanceOf(Evaluation::class, $reloaded);
        self::assertSame('DS VLAN (rattrapage)', $reloaded->getName());
        self::assertSame(2.0, $reloaded->getCoefficient());
        self::assertSame(20.0, $reloaded->getScale());
        self::assertSame('2026-10-15', $reloaded->getDate()?->format('Y-m-d'));
        self::assertEquals($visibleAt, $reloaded->getVisibleAt());
        self::assertSame($this->teacher->getId(), $reloaded->getLastUpdatedBy()?->getId());
    }

    public function testTheVisibilityIsMovedButNeverToNow(): void
    {
        $evaluation = $this->evaluation();
        $token = $this->accessTokenFor($this->teacher);

        $past = $this->callTool($token, 'evaluation_update', ['evaluationId' => $evaluation->getId(), 'name' => 'Autre nom', 'visibleAt' => '2020-01-01T08:00']);
        self::assertTrue($past['isError']);
        $this->entityManager->clear();
        // Refused whole: the name that came with the wrong date was not written either.
        self::assertSame('DS VLAN', $this->entityManager->find(Evaluation::class, $evaluation->getId())?->getName());

        $moved = $this->callTool($token, 'evaluation_update', ['evaluationId' => $evaluation->getId(), 'visibleAt' => '2031-01-06T08:00']);
        self::assertFalse($moved['isError'], $moved['text']);
        $this->entityManager->clear();
        self::assertSame('2031-01-06 08:00', $this->entityManager->find(Evaluation::class, $evaluation->getId())?->getVisibleAt()?->format('Y-m-d H:i'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedUpdateProvider(): iterable
    {
        yield 'no field named' => [[], 'Aucun champ'];
        yield 'an empty name' => [['name' => '  '], 'name'];
        yield 'a date that is not one' => [['date' => '15/10/2026'], 'AAAA-MM-JJ'];
        yield 'a scale below one' => [['scale' => 0], 'scale'];
        // An unknown value is refused, never read as the default: the update would change the type.
        yield 'a type that does not exist' => [['name' => 'Autre nom', 'type' => 'qcm'], 'type'];
    }

    /** @param array<string, mixed> $fields */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUpdateProvider')]
    public function testAnUpdateThatSaysNothingUsableChangesNothing(array $fields, string $expected): void
    {
        $evaluation = $this->evaluation();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'evaluation_update', ['evaluationId' => $evaluation->getId(), ...$fields]);

        self::assertTrue($result['isError'], $result['text']);
        self::assertStringContainsString($expected, $result['text']);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Evaluation::class, $evaluation->getId());
        self::assertInstanceOf(Evaluation::class, $reloaded);
        self::assertSame('DS VLAN', $reloaded->getName());
        self::assertSame('written', $reloaded->getType()->value);
    }

    /** Lowering what an evaluation is marked out of under a mark already entered is legal, and said. */
    public function testAScaleLoweredUnderAnEnteredGradeIsSaid(): void
    {
        $evaluation = $this->evaluation();
        $this->entityManager->persist((new Grade($evaluation, $this->student))->setValue(18.0));
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'evaluation_update', ['evaluationId' => $evaluation->getId(), 'scale' => 10]);

        self::assertFalse($result['isError'], $result['text']);
        $remark = $result['data']['remark'] ?? null;
        self::assertIsString($remark);
        self::assertStringContainsString('1 note', $remark);
    }

    /** Writing is the author's alone: a co-titulaire reads the evaluation and never rewrites it. */
    public function testAColleaguesEvaluationIsNotUpdated(): void
    {
        $evaluation = $this->evaluation();
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.cotitulaire');
        $this->program->addTeacher($colleague);
        $this->topic->addTeacher($colleague);
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($colleague), 'evaluation_update', ['evaluationId' => $evaluation->getId(), 'name' => 'Renommée']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('introuvable', $result['text']);
    }

    private function evaluation(): Evaluation
    {
        $evaluation = new Evaluation($this->topic, 'DS VLAN', new \DateTimeImmutable('2026-10-15'));
        $evaluation->setCreatedBy($this->teacher);
        $evaluation->setVisibleAt(new \DateTimeImmutable('2030-01-01 08:00:00'));
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        return $evaluation;
    }
}
