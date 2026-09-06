<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MessageThread;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudWordLength;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The student's endpoint, end to end.
 *
 * The rules it exercises are all tested on primitives elsewhere (WordCloudSubmissionPolicyTest,
 * WordCloudScheduleTest). What only this can show is that they are actually **wired**: the handoff
 * asks for the period to be refused « côté serveur, pas seulement masquée côté client », and a
 * screen whose form is hidden while its POST still accepts words would pass every unit test in the
 * repository.
 */
class WordCloudSubmissionTest extends FunctionalTestCase
{
    private User $student;
    private User $outsider;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'cloud.student');
        $this->outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'cloud.outsider');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'cloud.teacher');
    }

    public function testAnAddressedStudentSendsAWordWhileTheCloudIsOpen(): void
    {
        $cloud = $this->openCloud();

        $this->submit($cloud, ['pare-feu']);

        self::assertSame(['pare-feu'], $this->wordsOf($cloud));
    }

    /**
     * The line the handoff insists on: outside the period the server refuses, whatever a client
     * chooses to send.
     */
    public function testAClosedCloudRefusesAWordEvenThoughTheFormIsGone(): void
    {
        $cloud = $this->openCloud();
        $this->closeIt($cloud);

        $this->client->loginUser($this->student);
        // The screen itself still renders - it says the cloud is closed. What must not happen is
        // the POST behind it going through.
        $this->client->request('GET', '/my/word-clouds/'.$cloud->getId());
        $this->client->request('POST', '/my/word-clouds/'.$cloud->getId().'/submit', [
            '_token' => $this->csrfToken('word_cloud_submit'),
            'words' => ['phishing'],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->wordsOf($cloud));
    }

    public function testTheQuotaIsEnforcedOnTheServer(): void
    {
        $cloud = $this->openCloud();
        $this->entityManager()->refresh($cloud);
        $cloud->setWordsPerStudent(2);
        $this->entityManager()->flush();

        $this->submit($cloud, ['pare-feu', 'phishing', 'vpn']);

        self::assertSame(['pare-feu', 'phishing'], $this->wordsOf($cloud));
    }

    /** Two spellings of one word from one person are one proposal, whatever the grouping setting. */
    public function testTheSameStudentCannotSendTheSameWordTwice(): void
    {
        $cloud = $this->openCloud();

        $this->submit($cloud, ['pare-feu']);
        $this->submit($cloud, ['Pare-Feu']);

        self::assertSame(['pare-feu'], $this->wordsOf($cloud));
    }

    /** A cloud a student is not asked does not exist for them - a 404, not a refusal. */
    public function testAStudentOfAnotherClassCannotSeeTheCloudAtAll(): void
    {
        $cloud = $this->openCloud();

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/my/word-clouds/'.$cloud->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** Moderation on: the word is written, and it is not in the cloud yet. */
    public function testWithModerationOnAWordArrivesPendingRatherThanApproved(): void
    {
        $cloud = $this->openCloud(moderation: true);

        $this->submit($cloud, ['darkweb']);

        $submissions = $this->submissionsOf($cloud);
        self::assertCount(1, $submissions);
        self::assertSame(WordCloudModerationState::Pending, $submissions[0]->getModerationState());
        self::assertFalse($submissions[0]->isCounted());
    }

    public function testTheTeacherOfTheClassReachesThePilotScreen(): void
    {
        $cloud = $this->openCloud();

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The screen renders whether or not it can subscribe to the live feed.
     *
     * Minting the subscription cookie fails when the hub sits on a different second-level domain
     * from the request, and that used to take the whole screen down with it - no cloud, no
     * moderation queue, no participation, over a refresh mechanism. Found by CI, which runs in
     * exactly that shape.
     */
    public function testThePilotScreenStillRendersWhenTheLiveFeedCannotBeSubscribedTo(): void
    {
        $cloud = $this->openCloud();
        $path = '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId();

        $this->client->loginUser($this->teacher);
        // A hub on another domain: the cookie cannot be scoped, the board simply stays static.
        $this->client->request('GET', $path, [], [], ['HTTP_HOST' => 'elsewhere.example']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('cm-wc-cloud', (string) $this->client->getResponse()->getContent());
    }

    /** Writing and running are two doors: being in the class opens neither the other. */
    public function testAStudentOfTheClassIsRefusedThePilotScreen(): void
    {
        $cloud = $this->openCloud();

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * « Relancer » / « Message aux sans-réponse »: the message goes to whoever wrote nothing, and to
     * nobody else. Getting this wrong is not a cosmetic bug - it is nudging people who have already
     * answered, which reads as a reproach.
     */
    public function testTheReminderWritesToTheSilentStudentsAlone(): void
    {
        $cloud = $this->openCloud();
        $silent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'cloud.silent');
        $cloud->getProgram()->addStudent($silent);
        $this->entityManager()->flush();

        $this->submit($cloud, ['pare-feu']);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId());
        $this->client->request('POST', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId().'/remind', [
            '_token' => $this->csrfToken('word_cloud_pilot'),
        ]);

        $threads = $this->entityManager()->getRepository(MessageThread::class)->findAll();
        self::assertCount(1, $threads);
        self::assertSame(
            [$silent->getId()],
            array_map(static fn (User $one): ?int => $one->getId(), $threads[0]->getManualRecipients()->toArray()),
        );
    }

    /** Nobody silent, no thread: an empty reminder is one more thing for the teacher to delete. */
    public function testNoReminderIsWrittenWhenTheWholeClassHasAnswered(): void
    {
        $cloud = $this->openCloud();
        $this->submit($cloud, ['pare-feu']);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId());
        $this->client->request('POST', '/programs/'.$cloud->getProgram()->getId().'/tools/word-clouds/'.$cloud->getId().'/remind', [
            '_token' => $this->csrfToken('word_cloud_pilot'),
        ]);

        self::assertSame([], $this->entityManager()->getRepository(MessageThread::class)->findAll());
    }

    /** @param list<string> $words */
    private function submit(WordCloud $cloud, array $words): void
    {
        $this->client->loginUser($this->student);
        // Opens the session csrfToken() borrows.
        $this->client->request('GET', '/my/word-clouds/'.$cloud->getId());

        // One box at a time, as the student's form posts them: the endpoint takes what it can and
        // says why for the rest, so a refusal in the middle must not lose what came before.
        foreach ($words as $word) {
            $this->client->request('POST', '/my/word-clouds/'.$cloud->getId().'/submit', [
                '_token' => $this->csrfToken('word_cloud_submit'),
                'words' => [$word],
            ]);
        }
    }

    private function openCloud(bool $moderation = false): WordCloud
    {
        $program = $this->createProgram([$this->student], [$this->teacher]);
        $now = new \DateTimeImmutable();

        $cloud = new WordCloud();
        $cloud->setProgram($program)
            ->setTeacher($this->teacher)
            ->setCreatedBy($this->teacher)
            ->setName('Cybersécurité')
            ->setQuestion('Quels mots associez-vous à la cybersécurité ?')
            ->setWordLength(WordCloudWordLength::FreeExpression)
            ->setWordsPerStudent(null)
            ->setModeration($moderation)
            ->setOpensAt($now->modify('-5 minutes'))
            ->setClosesAt($now->modify('+30 minutes'));

        $this->entityManager()->persist($cloud);
        $this->entityManager()->flush();

        return $cloud;
    }

    private function closeIt(WordCloud $cloud): void
    {
        $cloud->setClosedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();
    }

    /** @return list<string> */
    private function wordsOf(WordCloud $cloud): array
    {
        return array_map(
            static fn (WordCloudSubmission $one): string => (string) $one->getText(),
            $this->submissionsOf($cloud),
        );
    }

    /** @return list<WordCloudSubmission> */
    private function submissionsOf(WordCloud $cloud): array
    {
        return static::getContainer()
            ->get(\App\Repository\WordCloudSubmissionRepository::class)
            ->findForCloud($cloud);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
