<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FeatureRoleSetting;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\Feature;
use App\Enum\VisibilityLevel;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudWordLength;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Service\WordCloud\WordCloudPublicCodes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A word cloud open to everyone (design/validated/word-cloud-public.md), end to end: a cloud of no
 * class created from the form, answered at /cloud/{code} by visitors without an account - each known
 * by its own cookie - and by accounts as themselves, piloted by its author alone, projected as a QR
 * code, and read by the Claude connector without a single name.
 */
class WordCloudPublicTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    private User $teacher;
    private User $colleague;
    private User $student;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'public.teacher');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'public.colleague');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'public.student');
        $this->program = $this->createProgram([$this->student], [$this->teacher, $this->colleague]);
        // A formation is hidden from its teachers until it is published; this one is.
        $this->program->setVisibility(VisibilityLevel::Everyone);
        $this->entityManager()->flush();
    }

    public function testACloudOfNoClassIsCreatedPublicAndPilotedByItsAuthorAlone(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/word-clouds/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="word_cloud"]')->form();
        $form['word_cloud[name]'] = 'Portes ouvertes';
        $form['word_cloud[question]'] = 'Un mot pour décrire le BTS ?';
        $form['word_cloud[program]'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects('/word-clouds?class=none');

        $cloud = $this->cloudNamed('Portes ouvertes');
        self::assertNull($cloud->getProgram());
        self::assertTrue($cloud->isPublic(), 'a cloud of no class is public whatever the switch said');
        self::assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', (string) $cloud->getPublicCode());

        $this->client->request('GET', '/word-clouds?class=none');
        self::assertStringContainsString('Portes ouvertes', (string) $this->client->getResponse()->getContent());

        $this->assertScreens($this->teacher, [
            '/word-clouds/'.$cloud->getId().'/projection' => 200,
            '/word-clouds/'.$cloud->getId().'/follow-up/students' => 200,
            '/word-clouds/'.$cloud->getId() => 200,
        ]);
        self::assertStringContainsString((string) $cloud->getPublicCode(), (string) $this->client->getResponse()->getContent());
        $this->assertScreens($this->teacher, ['/word-clouds/'.$cloud->getId().'/qr' => 200]);
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());

        // Its class's teachers are nobody here: a colleague does not find it.
        $this->assertScreens($this->colleague, ['/word-clouds/'.$cloud->getId() => 404]);
    }

    public function testEachVisitorWithoutAnAccountHasItsOwnQuotaAndNoName(): void
    {
        $cloud = $this->openCloud(null, wordsPerPerson: 2);
        $path = '/cloud/'.$cloud->getPublicCode();

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Un mot pour décrire le BTS ?', (string) $this->client->getResponse()->getContent());

        $this->post($path, ['réseau', 'Réseau']);
        self::assertNotNull($this->client->getCookieJar()->get('word_cloud_guest_'.$cloud->getId(), $path));
        // « Réseau » is the same word as « réseau » for the duplicate rule: one word kept.
        self::assertSame(['réseau'], $this->texts($cloud));

        $this->post($path, ['code', 'serveur']);
        self::assertSame(['réseau', 'code'], $this->texts($cloud), 'two words for this visitor, and no more');

        // Another phone - no cookie - is another person, with a quota of its own.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $path);
        $this->post($path, ['serveur']);
        self::assertSame(['réseau', 'code', 'serveur'], $this->texts($cloud));

        $submissions = $this->submissions($cloud);
        self::assertTrue($submissions[0]->isGuest());
        self::assertNull($submissions[0]->getStudent());
        self::assertSame(64, \strlen((string) $submissions[0]->getGuestTokenHash()));
        self::assertNotSame($submissions[0]->getGuestTokenHash(), $submissions[2]->getGuestTokenHash());

        // The follow-up gathers them on one line, the board counts two people.
        $this->assertScreens($this->teacher, ['/word-clouds/'.$cloud->getId().'/follow-up/students' => 200]);
        self::assertStringContainsString('Sans compte : 2 personnes', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAccountAnswersAPublicClassCloudAsItself(): void
    {
        $cloud = $this->openCloud($this->program);
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'public.outsider');

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/cloud/'.$cloud->getPublicCode());
        self::assertResponseIsSuccessful();
        $this->post('/cloud/'.$cloud->getPublicCode(), ['pare-feu']);

        $submissions = $this->submissions($cloud);
        self::assertCount(1, $submissions);
        self::assertSame($outsider->getId(), $submissions[0]->getStudent()?->getId());
        self::assertNull($this->client->getCookieJar()->get('word_cloud_guest_'.$cloud->getId(), '/cloud/'.$cloud->getPublicCode()), 'an account is never a guest');

        // The follow-up lists the class, then the account from outside it.
        $this->assertScreens($this->teacher, ['/word-clouds/'.$cloud->getId().'/follow-up/students' => 200]);
        self::assertStringContainsString('Public.outsider', (string) $this->client->getResponse()->getContent());
    }

    public function testAClosedPublicCloudTakesNothing(): void
    {
        $cloud = $this->openCloud(null);
        // Opened while it took words - the form is what starts the visitor's session -, then closed
        // under them.
        $this->client->request('GET', '/cloud/'.$cloud->getPublicCode());
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken('word_cloud_public');
        $this->entityManager()->createQuery('UPDATE '.WordCloud::class.' c SET c.closedAt = :now WHERE c.id = :id')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $cloud->getId())
            ->execute();

        $this->client->request('POST', '/cloud/'.$cloud->getPublicCode().'/submit', ['_token' => $token, 'words' => ['réseau']]);
        self::assertResponseRedirects('/cloud/'.$cloud->getPublicCode(), 303);

        self::assertSame([], $this->texts($cloud));
    }

    public function testACloudThatIsNotPublicOrWhoseAuthorLostTheFeatureDoesNotExistThere(): void
    {
        $cloud = $this->openCloud($this->program);
        $code = (string) $cloud->getPublicCode();
        $cloud->setPublic(false);
        $public = $this->openCloud(null);
        $this->entityManager()->flush();

        $this->client->request('GET', '/cloud/'.$code);
        self::assertResponseStatusCodeSame(404);
        $this->assertScreens($this->teacher, ['/word-clouds/'.$cloud->getId().'/qr' => 404]);
        $this->client->request('GET', '/cloud/'.$public->getPublicCode());
        self::assertResponseIsSuccessful();

        $this->entityManager()->createQuery('UPDATE '.FeatureRoleSetting::class.' s SET s.enabled = false WHERE s.feature = :feature')
            ->setParameter('feature', Feature::WordCloud)
            ->execute();
        $this->client->request('GET', '/cloud/'.$public->getPublicCode());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCodeIsFoundAsTypedOrPasted(): void
    {
        $cloud = $this->openCloud(null);
        $code = (string) $cloud->getPublicCode();

        $this->client->request('GET', '/cloud', ['code' => strtolower(substr($code, 0, 3).' '.substr($code, 3))]);
        self::assertResponseRedirects('/cloud/'.$code);
        $this->client->request('GET', '/cloud', ['code' => 'https://example.org/cloud/'.$code]);
        self::assertResponseRedirects('/cloud/'.$code);
        $this->client->request('GET', '/cloud', ['code' => 'ZZZZZZ']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/cloud');
        self::assertResponseIsSuccessful();

        self::assertNull(WordCloudPublicCodes::codeOf('??'));
    }

    public function testTheOldAddressesOfAClassCloudStillLeadToIt(): void
    {
        $cloud = $this->openCloud($this->program);
        $this->client->loginUser($this->teacher);
        $base = '/programs/'.$this->program->getId().'/tools/word-clouds';

        $this->client->request('GET', $base);
        self::assertResponseRedirects('/word-clouds?class='.$this->program->getId(), 301);
        $this->client->request('GET', $base.'/new');
        self::assertResponseRedirects('/word-clouds/new?program='.$this->program->getId(), 301);
        $this->client->request('GET', $base.'/'.$cloud->getId().'/follow-up/students');
        self::assertResponseRedirects('/word-clouds/'.$cloud->getId().'/follow-up/students', 301);
        $this->client->request('GET', $base.'/'.$cloud->getId());
        self::assertResponseRedirects('/word-clouds/'.$cloud->getId(), 301);
    }

    public function testTheConnectorReadsWordsAndFiguresWithoutAName(): void
    {
        $cloud = $this->openCloud($this->program, moderation: true);
        $em = $this->entityManager();
        $em->persist(new WordCloudSubmission($cloud, $this->student, 'Réseau', 'reseau', WordCloudModerationState::Approved));
        $em->persist(new WordCloudSubmission($cloud, null, 'réseau', 'reseau', WordCloudModerationState::Approved, str_repeat('a', 64)));
        $em->persist(new WordCloudSubmission($cloud, null, 'code', 'code', WordCloudModerationState::Approved, str_repeat('a', 64)));
        $em->persist(new WordCloudSubmission($cloud, null, 'insulte', 'insulte', WordCloudModerationState::Rejected, str_repeat('b', 64)));
        $em->persist(new WordCloudSubmission($cloud, null, 'attente', 'attente', WordCloudModerationState::Pending, str_repeat('c', 64)));
        $em->flush();

        // A guest's word waiting in the moderation queue: the pilot screen names it « Invité ».
        $this->assertScreens($this->teacher, ['/word-clouds/'.$cloud->getId() => 200]);
        self::assertStringContainsString('Invité', (string) $this->client->getResponse()->getContent());

        $token = $this->accessTokenFor($this->teacher);
        self::assertContains('word_cloud_get', $this->toolNames($token));

        $list = $this->callTool($token, 'word_cloud_list', ['programId' => $this->program->getId()]);
        self::assertFalse($list['isError'], $list['text']);
        self::assertIsArray($list['data']['wordClouds']);
        self::assertSame([$cloud->getId()], array_column($list['data']['wordClouds'], 'id'));
        $listed = $list['data']['wordClouds'][0];
        self::assertIsArray($listed);
        self::assertSame(4, $listed['participantCount']);

        $read = $this->callTool($token, 'word_cloud_get', ['cloudId' => $cloud->getId()]);
        self::assertFalse($read['isError'], $read['text']);
        $results = $read['data']['results'];
        self::assertIsArray($results);
        self::assertSame([['word' => 'Réseau', 'count' => 2, 'share' => 100], ['word' => 'code', 'count' => 1, 'share' => 50]], $results['words']);
        self::assertSame(4, $results['participantCount']);
        self::assertSame(1, $results['participantsWithAccount']);
        self::assertSame(3, $results['participantsWithoutAccount']);
        self::assertSame(1, $results['pendingWordCount']);
        self::assertSame(1, $results['rejectedWordCount']);
        self::assertIsString($read['data']['publicUrl']);
        self::assertStringContainsString('/cloud/'.$cloud->getPublicCode(), $read['data']['publicUrl']);

        // Not a name anywhere, nor the refused word.
        $payload = (string) json_encode($read['data'], \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('public.student', strtolower($payload));
        self::assertStringNotContainsString('insulte', $payload);

        // A teacher who does not pilot it is told it is not found.
        $other = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'public.other'));
        self::assertTrue($this->callTool($other, 'word_cloud_get', ['cloudId' => $cloud->getId()])['isError']);
    }

    private function openCloud(?Program $program, ?int $wordsPerPerson = null, bool $moderation = false): WordCloud
    {
        $now = new \DateTimeImmutable();
        $cloud = (new WordCloud())
            ->setProgram($program)
            ->setTeacher($this->teacher)
            ->setCreatedBy($this->teacher)
            ->setName('Portes ouvertes')
            ->setQuestion('Un mot pour décrire le BTS ?')
            ->setWordLength(WordCloudWordLength::OneWord)
            ->setWordsPerStudent($wordsPerPerson)
            ->setModeration($moderation)
            ->setPublic(true)
            ->setOpensAt($now->modify('-5 minutes'))
            ->setClosesAt($now->modify('+30 minutes'));
        static::getContainer()->get(WordCloudPublicCodes::class)->ensure($cloud);

        $this->entityManager()->persist($cloud);
        $this->entityManager()->flush();

        return $cloud;
    }

    /** @param list<string> $words */
    private function post(string $path, array $words): void
    {
        $this->client->request('POST', $path.'/submit', [
            '_token' => $this->csrfToken('word_cloud_public'),
            'words' => $words,
        ]);
        self::assertResponseRedirects($path, 303);
    }

    private function cloudNamed(string $name): WordCloud
    {
        $cloud = static::getContainer()->get(WordCloudRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(WordCloud::class, $cloud);

        return $cloud;
    }

    /** @return list<WordCloudSubmission> */
    private function submissions(WordCloud $cloud): array
    {
        return static::getContainer()->get(WordCloudSubmissionRepository::class)->findForCloud($cloud);
    }

    /** @return list<string> */
    private function texts(WordCloud $cloud): array
    {
        return array_map(static fn (WordCloudSubmission $one): string => (string) $one->getText(), $this->submissions($cloud));
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
