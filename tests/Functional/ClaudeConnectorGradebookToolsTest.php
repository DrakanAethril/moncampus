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
}
