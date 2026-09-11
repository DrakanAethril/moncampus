<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Evaluation;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Two teachers hold one matière: the carnet de notes is shared for reading and split for writing.
 *
 * This is the rule the whole multi-titulaire feature rests on, and it is asked here through real
 * HTTP rather than through the Voter alone, because the screens are where it can quietly go wrong:
 * a grid that renders every column editable, or an entry screen that refuses to open at all, both
 * pass a Voter test and fail the user.
 */
class GradebookSharedTopicTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $author;
    private User $colleague;
    private User $student;
    private Program $program;
    private Topic $topic;
    private Evaluation $authored;
    private Evaluation $colleagues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'shared.admin');
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'shared.author');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'shared.colleague');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'shared.student');

        $this->program = $this->createProgram([$this->student], [$this->author, $this->colleague], $admin);

        $topicGroup = new TopicGroup('Groupe de matières partagé', $this->program);
        $topicGroup->setCreatedBy($admin);
        $this->entityManager->persist($topicGroup);

        $this->topic = new Topic('Matière à deux titulaires', $this->program, $topicGroup);
        $this->topic->setCreatedBy($admin);
        $this->topic->addTeacher($this->author);
        $this->topic->addTeacher($this->colleague);
        $this->entityManager->persist($this->topic);

        $this->authored = $this->evaluation('Devoir du premier titulaire', $this->author);
        $this->colleagues = $this->evaluation('Devoir du second titulaire', $this->colleague);

        $this->entityManager->flush();
    }

    /**
     * The grid opens on the whole matière for both of them, and says column by column which ones
     * are theirs - a single "editable" for the screen was the shape before, and it cannot express
     * a matière whose columns belong to two people.
     */
    public function testTheGridIsEditableColumnByColumn(): void
    {
        $this->client->loginUser($this->colleague);
        $crawler = $this->client->request('GET', $this->gridUrl());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Typed at the boundary rather than cast further in, same rule as the app's own readings of
        // a JSON payload: only the two keys this assertion is about are declared.
        /** @var list<array{id: int, editable: bool}> $evaluations */
        $evaluations = json_decode(
            $crawler->filter('[data-evaluation-grid-evaluations-value]')->attr('data-evaluation-grid-evaluations-value') ?? '[]',
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $editableById = [];
        foreach ($evaluations as $evaluation) {
            $editableById[$evaluation['id']] = $evaluation['editable'];
        }

        self::assertCount(2, $editableById, 'both titulaires read the whole matière');
        self::assertTrue($editableById[$this->colleagues->getId()], 'a teacher edits the evaluation they posed');
        self::assertFalse($editableById[$this->authored->getId()], "a colleague's evaluation is read-only");
    }

    /** The entry screen opens read-only on a colleague's evaluation rather than refusing. */
    public function testTheEntryScreenOpensReadOnlyOnAColleaguesEvaluation(): void
    {
        $this->client->loginUser($this->colleague);
        $this->client->request('GET', $this->entryUrl($this->authored));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The one that matters: reading a colleague's column must never become writing it. The screen
     * draws it read-only, and the route refuses it anyway - the grid's own JSON is not the guard.
     */
    public function testACoTitulaireCannotGradeTheColleaguesEvaluation(): void
    {
        $this->client->loginUser($this->colleague);
        $this->client->request('GET', $this->gridUrl());

        $this->postGrade($this->authored, '12');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->postGrade($this->colleagues, '12');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'their own column stays theirs to fill');
    }

    /** A teacher of the class who holds none of its matières reaches no carnet at all. */
    public function testATeacherWhoIsNotATitulaireSeesNothing(): void
    {
        $stranger = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'shared.stranger');
        $this->program->addTeacher($stranger);
        $this->entityManager->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', $this->gridUrl());

        // The empty-carnet screen, not the grid: there is no matière of theirs to show.
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(
            0,
            $this->client->getCrawler()->filter('[data-evaluation-grid-evaluations-value]'),
            'a teacher with no matière in this class is offered no grid',
        );
    }

    private function evaluation(string $name, User $author): Evaluation
    {
        $evaluation = new Evaluation($this->topic, $name, new \DateTimeImmutable('today'));
        $evaluation->setCreatedBy($author);
        $this->entityManager->persist($evaluation);

        return $evaluation;
    }

    private function gridUrl(): string
    {
        return \sprintf('/programs/%d/gradebook?topic=%d', $this->program->getId(), $this->topic->getId());
    }

    private function entryUrl(Evaluation $evaluation): string
    {
        return \sprintf('/programs/%d/gradebook/evaluations/%d/entry', $this->program->getId(), $evaluation->getId());
    }

    private function postGrade(Evaluation $evaluation, string $raw): void
    {
        $this->client->request(
            'POST',
            \sprintf(
                '/programs/%d/gradebook/evaluations/%d/grades/%d',
                $this->program->getId(),
                $evaluation->getId(),
                $this->student->getId(),
            ),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken('gradebook_save'),
            ],
            content: json_encode(['raw' => $raw], \JSON_THROW_ON_ERROR),
        );
    }
}
