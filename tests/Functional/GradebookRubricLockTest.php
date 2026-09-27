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
use Doctrine\ORM\EntityManagerInterface;

/**
 * A barème that points were entered against is not rebuilt.
 *
 * EvaluationRubricBuilder::rebuild() replaces every section and question outright, and the answers
 * entered per question (GradeRubricAnswer) point at those questions through a foreign key with no
 * ON DELETE rule: saving the barème of an evaluation already graded died at flush time, as a 500.
 * The screen now refuses and says why; the Claude connector asks the same question first.
 */
class GradebookRubricLockTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $teacher;
    private Program $program;
    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'lock.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'lock.teacher');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'lock.student');
        $this->program = $this->createProgram([$student], [$this->teacher], $admin);

        $group = new TopicGroup('Groupe', $this->program);
        $group->setCreatedBy($admin);
        $topic = new Topic('Réseaux', $this->program, $group);
        $topic->setCreatedBy($admin);
        $topic->addTeacher($this->teacher);

        $this->evaluation = new Evaluation($topic, 'DS VLAN', new \DateTimeImmutable('today'));
        $this->evaluation->setCreatedBy($this->teacher);
        $section = new EvaluationRubricSection('Partie 1', 0, RubricSectionKind::Standard);
        $question = new EvaluationRubricQuestion('1a', 10.0, 0);
        $section->addQuestion($question);
        $this->evaluation->addRubricSection($section);

        $grade = new Grade($this->evaluation, $student);
        $grade->addRubricAnswer((new GradeRubricAnswer($grade, $question))->setPointsAwarded(7.0));

        foreach ([$group, $topic, $this->evaluation, $section, $grade] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function testTheScreenRefusesToRebuildTheBaremeOfAGradedEvaluation(): void
    {
        $url = \sprintf('/programs/%d/gradebook/evaluations/%d/grading-scale', $this->program->getId(), $this->evaluation->getId());
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $url);

        $this->client->request('POST', $url, [
            '_token' => $this->csrfToken('gradebook_save'),
            'sections' => [['name' => 'Partie 1', 'questions' => [['label' => '1a', 'maxPoints' => '12']]]],
        ]);

        $this->assertResponseRedirects($url);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Evaluation::class, $this->evaluation->getId());
        self::assertInstanceOf(Evaluation::class, $reloaded);
        $sections = $reloaded->getStandardRubricSections();
        self::assertCount(1, $sections);
        $question = $sections[0]->getQuestions()->first();
        self::assertInstanceOf(EvaluationRubricQuestion::class, $question);
        self::assertSame(10.0, $question->getMaxPoints());
    }
}
