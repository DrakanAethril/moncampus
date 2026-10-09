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
use App\Enum\RubricSectionKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The entry screen's two saves, as the browser makes them: one cell per student, one box per
 * question. They answer the row's new state, which the screen repaints from - and a box that
 * exceeds its question is refused with a code, never rewritten.
 */
class GradebookEntrySaveTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $teacher;
    private User $student;
    private Program $program;
    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'entry.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'entry.teacher');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'entry.student');
        $this->program = $this->createProgram([$this->student], [$this->teacher], $admin);

        $group = new TopicGroup('Groupe', $this->program);
        $group->setCreatedBy($admin);
        $this->topic = new Topic('Réseaux', $this->program, $group);
        $this->topic->setCreatedBy($admin);
        $this->topic->addTeacher($this->teacher);
        $this->entityManager->persist($group);
        $this->entityManager->persist($this->topic);
        $this->entityManager->flush();
    }

    public function testACellIsSavedThenEmptied(): void
    {
        $evaluation = $this->evaluation();
        $url = \sprintf('/programs/%d/gradebook/evaluations/%d/grades/%d', $this->program->getId(), $evaluation->getId(), $this->student->getId());
        $this->openEntryAs($this->teacher, $evaluation);

        $saved = $this->post($url, '14,5');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('normal', $saved['status'] ?? null);
        self::assertEquals(14.5, $saved['value'] ?? null);
        self::assertEquals(14.5, $saved['evaluationAverage'] ?? null);

        $cleared = $this->post($url, '');
        self::assertTrue($cleared['cleared'] ?? null);
        self::assertSame([], $this->entityManager->getRepository(Grade::class)->findBy(['evaluation' => $evaluation]));
    }

    public function testABoxIsSavedAndABoxAboveItsQuestionIsRefused(): void
    {
        $evaluation = $this->evaluation();
        $section = new EvaluationRubricSection('Partie 1', 0, RubricSectionKind::Standard);
        $section->addQuestion($question = new EvaluationRubricQuestion('1a', 4.0, 0));
        $evaluation->addRubricSection($section);
        $this->entityManager->persist($section);
        $this->entityManager->flush();

        $url = \sprintf('/programs/%d/gradebook/evaluations/%d/entry/grades/%d/questions/%d', $this->program->getId(), $evaluation->getId(), $this->student->getId(), $question->getId());
        $this->openEntryAs($this->teacher, $evaluation);

        $saved = $this->post($url, '3,5');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertEquals(3.5, $saved['total'] ?? null);

        self::assertSame('exceeds_max_points', $this->post($url, '5')['error'] ?? null);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid', $this->post($url, 'bien')['error'] ?? null);

        $this->entityManager->clear();
        $grade = $this->entityManager->getRepository(Grade::class)->findOneBy(['evaluation' => $evaluation->getId()]);
        self::assertSame(3.5, $grade?->getValue());
    }

    /** The screen shows the whole class's marks: a student of that class never opens it. */
    public function testAStudentDoesNotOpenTheEntryScreen(): void
    {
        $evaluation = $this->evaluation();

        $this->openEntryAs($this->student, $evaluation);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function evaluation(): Evaluation
    {
        $evaluation = new Evaluation($this->topic, 'DS', new \DateTimeImmutable('today'));
        $evaluation->setCreatedBy($this->teacher);
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        return $evaluation;
    }

    private function openEntryAs(User $user, Evaluation $evaluation): void
    {
        $this->client->loginUser($user);
        $this->client->request('GET', \sprintf('/programs/%d/gradebook/evaluations/%d/entry', $this->program->getId(), $evaluation->getId()));
    }

    /** @return array<array-key, mixed> */
    private function post(string $url, string $raw): array
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('gradebook_save'),
        ], content: (string) json_encode(['raw' => $raw]));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
