<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\LearningPathQuizAttempt;
use App\Entity\OnlineCourse;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Enum\OnlineCourseStatus;
use App\Enum\QuestionType;
use App\Service\LearningPath\LearningPathWriter;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A learning path followed end to end (design/validated/cours-en-ligne.md, §10 and §11): nothing
 * of it is read without an account, a validation quiz closes what follows it until its threshold
 * is reached, a course reserved for paths is read from the path alone, and the author - and nobody
 * else - reads who got how far.
 */
class LearningPathFollowTest extends FunctionalTestCase
{
    private User $teacher;
    private User $student;
    private EntityManagerInterface $em;
    private LearningPath $path;
    private OnlineCourse $reserved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'path.teacher');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'path.student');

        static::getContainer()->get(OnlineCoursePageHandles::class)->create($this->teacher, 'cours-sql', 'Cours de SQL');
        $public = $this->course('Le modèle relationnel', OnlineCourseStatus::PublicCourse);
        $this->reserved = $this->course('Les jointures SQL', OnlineCourseStatus::PathOnly);

        $writer = static::getContainer()->get(LearningPathWriter::class);
        $this->path = $writer->create($this->teacher, 'Bases de données relationnelles');
        $writer->addCourse($this->path, $public);
        $writer->addQuiz($this->path, $this->quiz(), 100);
        $writer->addCourse($this->path, $this->reserved);
        $writer->publish($this->path);
        $this->em->flush();
    }

    public function testNothingOfAPathIsReadWithoutAnAccount(): void
    {
        foreach (['/paths', '/paths/'.$this->path->getId(), '/paths/'.$this->path->getId().'/steps/1'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'), $path);
        }

        // Nor is a course reserved for paths, on its author's page.
        $this->client->request('GET', '/courses/cours-sql/'.$this->reserved->getSlug());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAQuizClosesTheNextStepUntilItsThresholdIsReached(): void
    {
        $id = (int) $this->path->getId();
        $this->assertScreens($this->student, ["/paths/$id" => 200, "/paths/$id/steps/1" => 302]);

        $this->client->request('POST', "/paths/$id/start", ['_token' => $this->csrfToken('learning_path')]);
        self::assertResponseRedirects("/paths/$id/steps/1");

        $this->assertScreens($this->student, ["/paths/$id/steps/1" => 200, "/paths/$id/steps/2" => 200, "/paths/$id/steps/3" => 302]);

        // A wrong attempt: the course after the quiz stays closed.
        $this->takeTheQuiz($id, right: false);
        $this->assertScreens($this->student, ["/paths/$id/steps/3" => 302]);
        self::assertNull($this->enrollment()->getCompletedAt());

        // A right one, drawn again: it opens, and reading it completes the path.
        $this->takeTheQuiz($id, right: true);
        $this->assertScreens($this->student, ["/paths/$id/steps/3" => 200, "/paths/$id" => 200, '/paths' => 200]);

        $this->em->clear();
        self::assertNotNull($this->enrollment()->getCompletedAt());
        $attempts = $this->em->getRepository(LearningPathQuizAttempt::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([0, 100], array_map(static fn (LearningPathQuizAttempt $attempt): ?int => $attempt->getScorePercent(), $attempts));
    }

    public function testTheFollowUpIsReadByTheAuthorAlone(): void
    {
        $id = (int) $this->path->getId();
        $this->assertScreens($this->student, ["/paths/$id" => 200]);
        $this->client->request('POST', "/paths/$id/start", ['_token' => $this->csrfToken('learning_path')]);

        $this->assertScreens($this->teacher, ["/tools/online-courses/paths/$id/tracking" => 200]);
        self::assertStringContainsString('Path.student', (string) $this->client->getResponse()->getContent());
        $this->assertScreens($this->teacher, ["/tools/online-courses/paths/$id/tracking.csv" => 200]);
        self::assertStringContainsString('Path.student', (string) $this->client->getResponse()->getContent());

        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'path.admin');
        $this->assertScreens($admin, ["/tools/online-courses/paths/$id/tracking" => 404, "/tools/online-courses/paths/$id" => 404]);
        $this->assertScreens($this->student, ["/tools/online-courses/paths/$id/tracking" => 404]);
    }

    public function testADraftPathIsItsAuthorsAloneToWalkThrough(): void
    {
        static::getContainer()->get(LearningPathWriter::class)->unpublish($this->path);
        $this->em->flush();
        $id = (int) $this->path->getId();

        $this->assertScreens($this->student, ["/paths/$id" => 404]);
        $this->assertScreens($this->teacher, ["/paths/$id" => 200]);
    }

    public function testACourseInAPathCannotBeDeleted(): void
    {
        static::getContainer()->get(OnlineCourseWriter::class)->unpublish($this->reserved);
        $this->em->flush();

        $this->assertScreens($this->teacher, ['/tools/online-courses/'.$this->reserved->getId() => 200]);
        $this->client->request('POST', '/tools/online-courses/'.$this->reserved->getId().'/delete', ['_token' => $this->csrfToken('online_course')]);

        $this->em->clear();
        self::assertNotNull($this->em->find(OnlineCourse::class, $this->reserved->getId()));
    }

    private function takeTheQuiz(int $id, bool $right): void
    {
        $this->client->request('POST', "/paths/$id/steps/2/attempts", ['_token' => $this->csrfToken('learning_path_quiz')]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $attemptUrl = (string) $this->client->getResponse()->headers->get('Location');
        $attemptId = (int) basename($attemptUrl);

        for ($index = 0; $index < 2; ++$index) {
            $this->em->clear();
            $attempt = $this->em->find(LearningPathQuizAttempt::class, $attemptId);
            self::assertInstanceOf(LearningPathQuizAttempt::class, $attempt);
            $question = $this->em->find(QuizQuestion::class, $attempt->getQuestions()[$index]['id']);
            self::assertInstanceOf(QuizQuestion::class, $question);
            $answer = array_values(array_filter($question->getAnswers()->toArray(), static fn (QuizAnswer $a): bool => $a->isCorrect() === $right))[0];

            $this->client->request('GET', $attemptUrl);
            self::assertResponseIsSuccessful();
            $this->client->request('POST', $attemptUrl, ['_token' => $this->csrfToken('learning_path_quiz'), 'index' => (string) $index, 'answers' => [(string) $answer->getId()]]);
            self::assertResponseRedirects($attemptUrl);
        }

        $this->client->request('GET', $attemptUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($right ? 'Quiz validé' : 'Pas encore', (string) $this->client->getResponse()->getContent());
    }

    private function enrollment(): LearningPathEnrollment
    {
        $enrollment = $this->em->getRepository(LearningPathEnrollment::class)->findOneBy(['path' => $this->path->getId()]);
        self::assertInstanceOf(LearningPathEnrollment::class, $enrollment);

        return $enrollment;
    }

    private function course(string $title, OnlineCourseStatus $status): OnlineCourse
    {
        $writer = static::getContainer()->get(OnlineCourseWriter::class);
        $course = $writer->create($this->teacher, $title);
        $course->setSummary('Un résumé.');
        $this->em->flush();

        $path = tempnam(sys_get_temp_dir(), 'path-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        static::getContainer()->get(OnlineCourseMaterialStore::class)->add($course, OnlineCourseMaterialKind::Pdf, new UploadedFile($path, 'cours.pdf', 'application/pdf', null, true));
        $writer->publish($course, $status);
        $this->em->flush();

        return $course;
    }

    private function quiz(): QuizTemplate
    {
        $quiz = new QuizTemplate($this->teacher);
        $quiz->setName('Tables et clés');
        $quiz->setCreatedBy($this->teacher);

        foreach (['Une clé primaire est-elle unique ?', 'Une clé étrangère référence-t-elle une autre table ?'] as $label) {
            $question = new QuizQuestion($quiz);
            $question->setType(QuestionType::Qcm);
            $question->setLabel($label);
            foreach (['Oui' => true, 'Non' => false] as $text => $correct) {
                $answer = new QuizAnswer($question);
                $answer->setLabel($text);
                $answer->setIsCorrect($correct);
                $question->addAnswer($answer);
            }
            $quiz->addQuestion($question);
        }

        $this->em->persist($quiz);
        $this->em->flush();

        return $quiz;
    }
}
