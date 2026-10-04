<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Enum\OnlineCourseStatus;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use App\Service\OnlineCourse\OnlineCourseHandleRefused;
use App\Service\OnlineCourse\OnlineCourseImageStore;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCoursePublicationRefused;
use App\Service\OnlineCourse\OnlineCourseTagResolver;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Cours en ligne », end to end (design/validated/cours-en-ligne.md): what a visitor with no
 * account reads, what they must not, and the rules the writers hold - a course is born a draft,
 * goes online only once it has what a page needs, and a page keeps answering on every address it
 * ever carried.
 */
class OnlineCoursePublicPagesTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'course.teacher');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAVisitorReadsAPublishedCourseAndNothingElse(): void
    {
        $page = $this->page($this->teacher, 'tharaud');
        $public = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $draft = $this->course($this->teacher, 'Le modèle OSI');

        $this->assertAnonymous('/courses/tharaud', 200);
        self::assertStringContainsString('Les jointures SQL', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Le modèle OSI', (string) $this->client->getResponse()->getContent());

        $this->assertAnonymous('/courses/tharaud/'.$public->getSlug(), 200);
        // Under the teacher's banner, which leads back to their page, and with no footer: the
        // course's title is the one heading.
        $crawler = $this->client->getCrawler();
        self::assertSame('/courses/tharaud', $crawler->filter('.cm-pub-banner a.cm-pub-banner__link')->attr('href'));
        self::assertSame(['Les jointures SQL'], $crawler->filter('h1')->each(static fn ($title): string => trim($title->text())));
        self::assertCount(0, $crawler->filter('.cm-pub__bar, .cm-pub__foot'));
        $this->assertAnonymous('/courses/tharaud/'.$public->getSlug().'/pdf', 200);
        $this->assertAnonymous('/courses/tharaud/'.$public->getSlug().'/video', 404);
        $this->assertAnonymous('/courses/tharaud/'.$draft->getSlug(), 404);
        $this->assertAnonymous('/courses/tharaud/nothing-here', 404);
        $this->assertAnonymous('/courses/nobody', 404);

        // The author sees their draft where it will be; a colleague and an administrator do not.
        $this->assertScreens($this->teacher, ['/courses/tharaud/'.$draft->getSlug() => 200]);
        $this->assertScreens($this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'course.admin'), ['/courses/tharaud/'.$draft->getSlug() => 404]);

        self::assertSame('tharaud', $page->getHandle());
    }

    public function testAPageWithNothingPublishedExistsForItsOwnerOnly(): void
    {
        $this->page($this->teacher, 'tharaud');
        $this->course($this->teacher, 'Encore un brouillon');

        $this->assertAnonymous('/courses/tharaud', 404);
        $this->assertScreens($this->teacher, ['/courses/tharaud' => 200]);
    }

    public function testThereIsNoCatalogueAboveAPage(): void
    {
        $this->page($this->teacher, 'tharaud');
        $this->course($this->teacher, 'Les jointures SQL', publish: true);

        $this->assertScreens($this->teacher, ['/courses' => 404, '/courses/' => 404]);
    }

    public function testFiltersNeverAnswerAnError(): void
    {
        $this->page($this->teacher, 'tharaud');
        $this->course($this->teacher, 'Les jointures SQL', publish: true);

        foreach (['?q=', '?tag=', '?tag=sql', '?tag[]=sql&tag[]=slam', '?view=themes', '?view=', '?tag[x][y]=1'] as $query) {
            $this->assertAnonymous('/courses/tharaud'.$query, 200);
        }
    }

    public function testAPageShowsItsBannerAndItsCoursesInARowPerTag(): void
    {
        $page = $this->page($this->teacher, 'tharaud');
        $page->setTitle('Les cours de S. Tharaud')->setBannerColor('#204060')->setTitleColor('#FFEE00')->setBannerHeight(9000);
        $tags = static::getContainer()->get(OnlineCourseTagResolver::class);
        $joins = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $tags->apply($joins, ['SQL', 'Bases de données']);
        $osi = $this->course($this->teacher, 'Le modèle OSI', publish: true);
        $tags->apply($osi, ['Réseau']);
        $this->em->flush();

        $this->assertAnonymous('/courses/tharaud', 200);
        $crawler = $this->client->getCrawler();
        // The colours as typed, lower-cased; a height out of bounds kept to the highest.
        self::assertSame('Les cours de S. Tharaud', trim($crawler->filter('.cm-pub-banner__title')->text()));
        self::assertStringContainsString('--cm-pub-banner-bg: #204060; --cm-pub-banner-ink: #ffee00; --cm-pub-banner-height: 600px;', (string) $crawler->filter('.cm-pub-banner')->attr('style'));
        // The recent row first, with every course, then the tags in alphabetical order.
        self::assertSame(['Récemment mis à jour 2', 'Bases de données 1', 'Réseau 1', 'SQL 1'], $crawler->filter('.cm-pub-row__title')->each(static fn ($title): string => preg_replace('/\s+/', ' ', trim($title->text())) ?? ''));
        self::assertSame('/courses/tharaud?tag=reseau', $crawler->filter('.cm-pub-row')->eq(2)->filter('.cm-pub-row__all')->attr('href'));
        // The teacher's page, not the establishment's: no emblem, no footer.
        self::assertCount(0, $crawler->filter('.cm-pub__medallion, .cm-pub__foot'));

        $this->assertAnonymous('/courses/tharaud?tag=reseau', 200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Le modèle OSI', $content);
        self::assertStringNotContainsString('Les jointures SQL', $content);
    }

    public function testATagPageIsSortedByNameUnlessTheLastModifiedIsAsked(): void
    {
        $this->page($this->teacher, 'tharaud');
        $tags = static::getContainer()->get(OnlineCourseTagResolver::class);
        $model = $this->course($this->teacher, 'Le modèle relationnel', publish: true);
        $tags->apply($model, ['SQL']);
        $joins = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $tags->apply($joins, ['SQL']);
        $acl = $this->course($this->teacher, 'Des droits SQL', publish: true);
        $tags->apply($acl, ['SQL']);
        foreach ([[$joins, '2026-09-01'], [$acl, '2026-09-10'], [$model, '2026-09-20']] as [$course, $date]) {
            (new \ReflectionProperty(OnlineCourse::class, 'updatedAt'))->setValue($course, new \DateTimeImmutable($date));
        }
        $this->em->flush();

        $titles = fn (): array => $this->client->getCrawler()->filter('.cm-pub-card__title')->each(static fn ($title): string => trim($title->text()));

        $this->assertAnonymous('/courses/tharaud?tag=sql', 200);
        self::assertSame(['Des droits SQL', 'Le modèle relationnel', 'Les jointures SQL'], $titles());
        self::assertSame('name', $this->client->getCrawler()->filter('#public-course-sort option[selected]')->attr('value'));

        $this->assertAnonymous('/courses/tharaud?tag=sql&sort=modified', 200);
        self::assertSame(['Le modèle relationnel', 'Des droits SQL', 'Les jointures SQL'], $titles());

        // Neither the rows nor « Récemment mis à jour » nor a search offer it.
        foreach (['', '?view=recent', '?q=sql', '?q=sql&tag=sql&sort=name'] as $query) {
            $this->assertAnonymous('/courses/tharaud'.$query, 200);
            self::assertCount(0, $this->client->getCrawler()->filter('#public-course-sort'), $query);
        }
    }

    public function testASignedInVisitorFindsTheirMenuAndTheWayBack(): void
    {
        $this->page($this->teacher, 'tharaud');
        $this->course($this->teacher, 'Les jointures SQL', publish: true);

        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'course.reader'));
        $crawler = $this->client->request('GET', '/courses/tharaud');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cm-pub-banner__bar .cm-user__avatar'));
        self::assertCount(1, $crawler->filter('.cm-pub-banner__bar a[href="/logout"]'));
        self::assertCount(1, $crawler->filter('.cm-pub-banner__bar a[href="/"]'));
    }

    public function testAnAddressThePageLeftKeepsRedirecting(): void
    {
        $page = $this->page($this->teacher, 'tharaud');
        $course = $this->course($this->teacher, 'Les jointures SQL', publish: true);

        static::getContainer()->get(OnlineCoursePageHandles::class)->change($page, 'cours-de-sql');
        $this->em->flush();

        $this->assertAnonymous('/courses/cours-de-sql', 200);
        $this->assertAnonymous('/courses/tharaud', 301);
        self::assertSame('/courses/cours-de-sql', $this->client->getResponse()->headers->get('Location'));

        $this->assertAnonymous('/courses/tharaud/'.$course->getSlug().'/pdf', 301);
        self::assertSame('/courses/cours-de-sql/'.$course->getSlug().'/pdf', $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnAddressIsNeverGivenToSomebodyElseAndItsOwnerMayTakeItBack(): void
    {
        $handles = static::getContainer()->get(OnlineCoursePageHandles::class);
        $page = $this->page($this->teacher, 'tharaud');
        $handles->change($page, 'cours-de-sql');
        $this->em->flush();

        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'course.colleague');
        self::assertSame('onlineCourseHandleTakenMessage', $handles->refusal('tharaud', $colleague));
        self::assertSame('onlineCourseHandleTakenMessage', $handles->refusal('cours-de-sql', $colleague));

        $handles->change($page, 'tharaud');
        $this->em->flush();
        self::assertSame('tharaud', $page->getHandle());
        self::assertSame(['cours-de-sql'], $handles->formerHandles($page));
    }

    public function testAnAddressIsNeitherALoginNorAReservedWord(): void
    {
        $handles = static::getContainer()->get(OnlineCoursePageHandles::class);

        self::assertSame('onlineCourseHandleIsLoginMessage', $handles->refusal('course-teacher', $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'course-teacher')));
        self::assertSame('onlineCourseHandleReservedMessage', $handles->refusal('paths', $this->teacher));
        self::assertSame('onlineCourseHandleFormatMessage', $handles->refusal('ab', $this->teacher));
        self::assertSame('sebastien-tharaud', $handles->normalize('  Sébastien Tharaud '));

        $this->expectException(OnlineCourseHandleRefused::class);
        $handles->create($this->teacher, 'paths', 'Cours');
    }

    public function testPublishingNamesEverythingThatIsMissing(): void
    {
        $writer = static::getContainer()->get(OnlineCourseWriter::class);
        $course = $writer->create($this->teacher, 'Les jointures SQL');
        $this->em->flush();

        self::assertSame(OnlineCourseStatus::Draft, $course->getStatus());
        self::assertSame(
            ['onlineCoursePublishNeedsSummaryMessage', 'onlineCoursePublishNeedsMaterialMessage', 'onlineCoursePublishNeedsPageMessage'],
            $writer->publishRefusals($course),
        );

        try {
            $writer->publish($course);
            self::fail('A course with no material went online.');
        } catch (OnlineCoursePublicationRefused $refused) {
            self::assertCount(3, $refused->reasons);
        }

        self::assertSame(OnlineCourseStatus::Draft, $course->getStatus());
    }

    public function testASecondCourseOfTheSameTitleGetsANumberedAddress(): void
    {
        $writer = static::getContainer()->get(OnlineCourseWriter::class);
        $first = $writer->create($this->teacher, 'Introduction');
        $this->em->flush();
        $second = $writer->create($this->teacher, 'Introduction');
        $this->em->flush();

        self::assertSame('introduction', $first->getSlug());
        self::assertSame('introduction-2', $second->getSlug());
    }

    public function testTheAddressOfACourseIsFrozenByItsFirstPublication(): void
    {
        $this->page($this->teacher, 'tharaud');
        $course = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $slug = $course->getSlug();

        static::getContainer()->get(OnlineCourseWriter::class)->unpublish($course);
        $course->setSlug('autre-adresse');

        self::assertSame($slug, $course->getSlug());
    }

    public function testReplacingAMaterialKeepsItsAddressAndTheRevisionBefore(): void
    {
        $store = static::getContainer()->get(OnlineCourseMaterialStore::class);
        $this->page($this->teacher, 'tharaud');
        $course = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $material = $course->findMaterialBySlug('pdf');
        self::assertNotNull($material);
        $first = $material->getLive()?->getStorageKey();

        $store->replace($material, $this->pdf('deuxieme.pdf'));
        $this->em->flush();

        self::assertSame('pdf', $material->getSlug());
        self::assertSame(2, $material->getLiveRevisionNumber());
        self::assertNotSame($first, $material->getLive()?->getStorageKey());
        self::assertSame($first, $material->getOther()?->getStorageKey());

        // A third file: two revisions are kept, never three.
        $store->replace($material, $this->pdf('troisieme.pdf'));
        $this->em->flush();
        self::assertCount(2, $material->getRevisions());
        self::assertSame(2, $material->getOther()?->getNumber());

        self::assertTrue($store->switchToOtherRevision($material));
        self::assertSame(2, $material->getLiveRevisionNumber());
    }

    public function testAMaterialOnlyTakesTheFilesOfItsNature(): void
    {
        $store = static::getContainer()->get(OnlineCourseMaterialStore::class);
        $course = $this->course($this->teacher, 'Les jointures SQL');

        $this->expectException(OnlineCourseMaterialRefused::class);
        $store->add($course, OnlineCourseMaterialKind::Video, $this->pdf('pas-une-video.pdf'));
    }

    public function testAnInteractiveCourseIsUnpackedIntoAFolderOfItsOwn(): void
    {
        $store = static::getContainer()->get(OnlineCourseMaterialStore::class);
        $storage = static::getContainer()->get('uploads.storage');
        $course = $this->course($this->teacher, 'Les jointures SQL');

        $material = $store->add($course, OnlineCourseMaterialKind::Interactive, $this->archive([
            'cours/index.html' => '<h1>Les jointures</h1>',
            'cours/assets/app.js' => 'console.log(1)',
            'cours/.DS_Store' => '',
        ]));
        $this->em->flush();

        $live = $material->getLive();
        self::assertNotNull($live);
        self::assertSame(2, $live->getFileCount());
        self::assertStringEndsWith('/r1/index.html', $live->getStorageKey());
        self::assertStringStartsWith('online-courses/'.$course->getId().'/'.$course->getStorageToken().'/', $live->getStorageKey());
        self::assertSame('<h1>Les jointures</h1>', $storage->read($live->getStorageKey()));
        self::assertTrue($storage->fileExists($live->getStoragePrefix().'assets/app.js'));
        self::assertFalse($storage->fileExists($live->getStoragePrefix().'.DS_Store'));

        // The interactive course is what the page opens on, although the PDF was there first.
        self::assertSame(0, $material->getPosition());
        self::assertSame(1, $course->findMaterialBySlug('pdf')?->getPosition());

        // A new archive is a new folder: the address of the bytes changes, the material's does not.
        $store->replace($material, $this->archive(['index.html' => '<h1>Version 2</h1>']));
        $this->em->flush();
        self::assertSame('interactive', $material->getSlug());
        self::assertStringEndsWith('/r2/index.html', (string) $material->getLive()?->getStorageKey());
        self::assertSame('<h1>Les jointures</h1>', $storage->read((string) $material->getOther()?->getStorageKey()));
    }

    public function testARefusedArchiveLeavesTheMaterialAsItWas(): void
    {
        $store = static::getContainer()->get(OnlineCourseMaterialStore::class);
        $course = $this->course($this->teacher, 'Les jointures SQL');
        $material = $store->add($course, OnlineCourseMaterialKind::Interactive, $this->archive(['index.html' => '<h1>Bon</h1>']));
        $this->em->flush();

        try {
            $store->replace($material, $this->archive(['index.html' => 'a', 'evil.php' => '<?php']));
            self::fail('An archive holding a PHP file was published.');
        } catch (OnlineCourseMaterialRefused $refused) {
            self::assertSame('onlineCourseBundleForbiddenFileMessage', $refused->getMessage());
        }

        self::assertSame(1, $material->getLiveRevisionNumber());
        self::assertCount(1, $material->getRevisions());
    }

    public function testAPageWrittenAsTextBecomesAnInteractiveCourse(): void
    {
        $store = static::getContainer()->get(OnlineCourseMaterialStore::class);
        $course = $this->course($this->teacher, 'Les jointures SQL');

        $material = $store->addInteractivePage($course, '<!doctype html><title>Cours</title><h1>Écrit par Claude</h1>');
        $this->em->flush();

        self::assertSame(OnlineCourseMaterialKind::Interactive, $material->getKind());
        $live = $material->getLive();
        self::assertNotNull($live);
        self::assertSame(1, $live->getFileCount());
        self::assertStringContainsString('Écrit par Claude', static::getContainer()->get('uploads.storage')->read($live->getStorageKey()));

        $this->expectException(OnlineCourseMaterialRefused::class);
        $store->addInteractivePage($course, '   ');
    }

    /**
     * The frame of an interactive course is drawn exactly when the content origin is another host
     * than the application - and never otherwise, where the screens say the course cannot be shown
     * rather than run it as the platform. Which of the two this environment is depends on its
     * configuration (a CDN domain or none), so the test reads the guard and pins the screens to it;
     * the guard itself is pinned by tests/Service/OnlineCourse/OnlineCourseContentOriginTest.php.
     */
    public function testAnInteractiveCourseIsFramedOnlyFromAnotherOrigin(): void
    {
        $this->page($this->teacher, 'tharaud');
        $course = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        static::getContainer()->get(OnlineCourseMaterialStore::class)->add($course, OnlineCourseMaterialKind::Interactive, $this->archive(['index.html' => '<h1>Cours</h1>']));
        $this->em->flush();

        $isolated = static::getContainer()->get(OnlineCourseContentOrigin::class)->isIsolatedFrom('localhost');

        foreach (['/courses/tharaud/les-jointures-sql/interactive', '/courses/tharaud/les-jointures-sql/interactive/play'] as $path) {
            $this->assertAnonymous($path, 200);
            $html = (string) $this->client->getResponse()->getContent();

            self::assertSame($isolated, str_contains($html, 'sandbox="allow-scripts allow-same-origin'), $path);
            self::assertSame(!$isolated, str_contains($html, 'ne peut pas être affiché'), $path);
            self::assertStringNotContainsString('allow-top-navigation', $html, $path);
        }

        // A PDF has a full page too; a video has none, the browser's own controls being enough.
        $this->assertAnonymous('/courses/tharaud/les-jointures-sql/pdf/play', 200);
        $this->assertAnonymous('/courses/tharaud/les-jointures-sql/interactive/play/more', 404);
    }

    public function testACoursePictureShowsOnItsCardAndInALinkPreviewUntilRemoved(): void
    {
        $this->page($this->teacher, 'tharaud');
        $course = $this->course($this->teacher, 'Les jointures SQL', publish: true);
        $path = tempnam(sys_get_temp_dir(), 'course-png-');
        self::assertIsString($path);
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        static::getContainer()->get(OnlineCourseImageStore::class)->set($course, new UploadedFile($path, 'vignette.png', 'image/png', null, true));
        $this->em->flush();
        $key = (string) $course->getImageKey();

        $this->assertAnonymous('/courses/tharaud', 200);
        self::assertStringContainsString('cm-pub-card__image', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString($key, (string) $this->client->getResponse()->getContent());
        $this->assertAnonymous('/courses/tharaud/'.$course->getSlug(), 200);
        self::assertSelectorExists('meta[property="og:image"][content$="'.$key.'"]');

        // A picture is no material: the store refuses a PDF.
        try {
            static::getContainer()->get(OnlineCourseImageStore::class)->set($course, $this->pdf('cours.pdf'));
            self::fail('A PDF is not a picture.');
        } catch (OnlineCourseMaterialRefused $refused) {
            self::assertSame('onlineCourseImageWrongTypeMessage', $refused->getMessage());
        }

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/tools/online-courses/'.$course->getId());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="online_course"]')->form();
        $removeImage = $form['online_course[removeImage]'];
        self::assertInstanceOf(ChoiceFormField::class, $removeImage);
        $removeImage->tick();
        $this->client->submit($form);
        $this->assertResponseRedirects('/tools/online-courses/'.$course->getId());

        $this->em->clear();
        $reloaded = $this->em->find(OnlineCourse::class, $course->getId());
        self::assertNull($reloaded?->getImageKey());
        self::assertEquals(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM deleted_object WHERE storage_key LIKE ?', ['%'.$key]));
    }

    private function assertAnonymous(string $path, int $expected): void
    {
        $this->client->getCookieJar()->clear();
        static::getContainer()->get('security.token_storage')->setToken(null);
        $this->client->request('GET', $path);

        self::assertSame($expected, $this->client->getResponse()->getStatusCode(), \sprintf('GET %s with no account', $path));
    }

    private function page(User $owner, string $handle): OnlineCoursePage
    {
        $page = static::getContainer()->get(OnlineCoursePageHandles::class)->create($owner, $handle, 'Cours de test');
        $this->em->flush();

        return $page;
    }

    private function course(User $owner, string $title, bool $publish = false): OnlineCourse
    {
        $writer = static::getContainer()->get(OnlineCourseWriter::class);
        $course = $writer->create($owner, $title);
        $course->setSummary('Un résumé.');
        $this->em->flush();

        static::getContainer()->get(OnlineCourseMaterialStore::class)->add($course, OnlineCourseMaterialKind::Pdf, $this->pdf('cours.pdf'));
        if ($publish) {
            $writer->publish($course);
        }
        $this->em->flush();

        return $course;
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'course-zip-');
        self::assertIsString($path);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile($path, 'cours.zip', 'application/zip', null, true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'course-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
