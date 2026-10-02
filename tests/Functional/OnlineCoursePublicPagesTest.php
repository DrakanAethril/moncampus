<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Enum\OnlineCourseStatus;
use App\Service\OnlineCourse\OnlineCourseHandleRefused;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCoursePublicationRefused;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;
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

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'course-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
