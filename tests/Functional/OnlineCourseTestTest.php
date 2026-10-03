<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineCourse;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Enum\QuestionType;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCourseQuizRefused;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A course's test (« Test » on its card): the quiz its author linked, taken by whoever reads the
 * course - without an account too - as many times as wanted, and recorded nowhere.
 */
class OnlineCourseTestTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $em;
    private OnlineCourseWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->writer = static::getContainer()->get(OnlineCourseWriter::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'test.teacher');
        static::getContainer()->get(OnlineCoursePageHandles::class)->create($this->teacher, 'cours-sql', 'Cours de SQL');
        $this->em->flush();
    }

    public function testAVisitorTakesTheTestAsOftenAsTheyLike(): void
    {
        $course = $this->course('Les clés', publish: true);
        $this->writer->linkQuiz($course, $this->quiz($this->teacher));
        $this->em->flush();
        $url = '/courses/cours-sql/'.$course->getSlug().'/test';

        $this->client->request('GET', '/courses/cours-sql');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.cm-pub-card__test[href="'.$url.'"]');

        $this->takeTheTest($url, right: true);
        self::assertStringContainsString('100 %', (string) $this->client->getResponse()->getContent());

        // Again, from the start: a new run, nothing kept of the previous one but in the session.
        $this->takeTheTest($url, right: false);
        self::assertStringContainsString('0 %', (string) $this->client->getResponse()->getContent());
    }

    public function testNoQuizNoTest(): void
    {
        $course = $this->course('Sans test', publish: true);

        $this->client->request('GET', '/courses/cours-sql');
        self::assertSelectorNotExists('a.cm-pub-card__test');
        $this->client->request('GET', '/courses/cours-sql/'.$course->getSlug().'/test');
        self::assertResponseStatusCodeSame(404);
    }

    public function testADraftsTestIsItsAuthorsAlone(): void
    {
        $course = $this->course('Brouillon', publish: false);
        $this->writer->linkQuiz($course, $this->quiz($this->teacher));
        $this->em->flush();
        $url = '/courses/cours-sql/'.$course->getSlug().'/test';

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->assertScreens($this->teacher, [$url => 200]);
    }

    public function testOnlyTheAuthorsOwnQuizWithQuestionsBecomesATest(): void
    {
        $course = $this->course('Les clés', publish: false);

        try {
            $this->writer->linkQuiz($course, $this->quiz($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'test.colleague')));
            self::fail('A colleague\'s quiz is not the author\'s.');
        } catch (OnlineCourseQuizRefused $refused) {
            self::assertSame('onlineCourseQuizNotOwnMessage', $refused->getMessage());
        }

        $empty = new QuizTemplate($this->teacher);
        $empty->setName('Vide');
        $empty->setCreatedBy($this->teacher);
        $this->em->persist($empty);
        $this->em->flush();

        $this->expectException(OnlineCourseQuizRefused::class);
        $this->writer->linkQuiz($course, $empty);
    }

    public function testTheCardLinksAndUnlinksTheQuiz(): void
    {
        $course = $this->course('Les clés', publish: false);
        $quiz = $this->quiz($this->teacher);

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/tools/online-courses/'.$course->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-picker-item][data-value="'.$quiz->getId().'"]');

        $form = $crawler->filter('form[name="online_course"]')->form();
        $form['online_course[quiz]'] = (string) $quiz->getId();
        $this->client->submit($form);
        self::assertResponseRedirects('/tools/online-courses/'.$course->getId());
        $this->em->clear();
        self::assertSame($quiz->getId(), $this->em->find(OnlineCourse::class, $course->getId())?->getQuizTemplate()?->getId());

        $crawler = $this->client->request('GET', '/tools/online-courses/'.$course->getId());
        $form = $crawler->filter('form[name="online_course"]')->form();
        $form['online_course[quiz]'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects('/tools/online-courses/'.$course->getId());
        $this->em->clear();
        self::assertNull($this->em->find(OnlineCourse::class, $course->getId())?->getQuizTemplate());
    }

    private function takeTheTest(string $url, bool $right): void
    {
        $crawler = $this->client->request('GET', $url);

        for ($guard = 0; $guard < 5 && 200 === $this->client->getResponse()->getStatusCode(); ++$guard) {
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form.cm-lp-question__body');
            self::assertCount(1, $form);

            $this->client->request('POST', $url, [
                '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
                'index' => (string) $form->filter('input[name="index"]')->attr('value'),
                'answers' => [$this->answerId($form, $right ? 'Oui' : 'Non')],
            ]);
            self::assertTrue($this->client->getResponse()->isRedirect());
            $crawler = $this->client->followRedirect();
            if (str_ends_with((string) $this->client->getRequest()->getPathInfo(), '/result')) {
                return;
            }
        }

        self::fail('The test never reached its result.');
    }

    private function answerId(Crawler $form, string $label): string
    {
        foreach ($form->filter('label.cm-radiocard') as $node) {
            $row = new Crawler($node);
            if (trim($row->filter('.cm-radiocard__title')->text()) === $label) {
                return (string) $row->filter('input')->attr('value');
            }
        }

        self::fail("No answer « $label ».");
    }

    private function course(string $title, bool $publish): OnlineCourse
    {
        $course = $this->writer->create($this->teacher, $title);
        $course->setSummary('Un résumé.');
        $this->em->flush();

        $path = tempnam(sys_get_temp_dir(), 'test-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        static::getContainer()->get(OnlineCourseMaterialStore::class)->add($course, OnlineCourseMaterialKind::Pdf, new UploadedFile($path, 'cours.pdf', 'application/pdf', null, true));
        if ($publish) {
            $this->writer->publish($course);
        }
        $this->em->flush();

        return $course;
    }

    private function quiz(User $teacher): QuizTemplate
    {
        $quiz = new QuizTemplate($teacher);
        $quiz->setName('Tables et clés');
        $quiz->setCreatedBy($teacher);

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
