<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterial;
use App\Entity\OnlineCourseTag;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Repository\OnlineCourseRepository;
use App\Service\OnlineCourse\OnlineCoursePublicPage;
use PHPUnit\Framework\TestCase;

/**
 * What a teacher's public page shows of a list of courses: the newest first, one row per tag in
 * alphabetical order holding its courses by title, and filters that narrow.
 */
class OnlineCoursePublicPageTest extends TestCase
{
    private User $owner;
    private OnlineCoursePublicPage $page;

    protected function setUp(): void
    {
        $this->owner = new User('owner');
        $this->page = new OnlineCoursePublicPage($this->createStub(OnlineCourseRepository::class));
    }

    public function testSeveralTagsNarrowTogether(): void
    {
        $joins = $this->course('Les jointures SQL', ['SQL', 'SLAM']);
        $model = $this->course('Le modèle relationnel', ['SQL']);
        $osi = $this->course('Le modèle OSI', ['Réseau']);

        self::assertSame([$joins, $model], $this->page->filter([$joins, $model, $osi], '', ['sql']));
        self::assertSame([$joins], $this->page->filter([$joins, $model, $osi], '', ['sql', 'slam']));
    }

    public function testATagNobodyCarriesFiltersOnNothingRatherThanOnEverything(): void
    {
        $joins = $this->course('Les jointures SQL', ['SQL']);

        self::assertSame([], $this->page->filter([$joins], '', ['renomme-depuis']));
    }

    public function testTheSearchReadsTitleSummaryAndTagsWhateverTheAccents(): void
    {
        $osi = $this->course('Le modèle OSI', ['Réseau'], 'Les sept couches.');
        $joins = $this->course('Les jointures SQL', ['SQL']);

        self::assertSame([$osi], $this->page->filter([$osi, $joins], 'MODELE', []));
        self::assertSame([$osi], $this->page->filter([$osi, $joins], 'reseau', []));
        self::assertSame([$osi], $this->page->filter([$osi, $joins], 'couches', []));
        self::assertSame([$osi, $joins], $this->page->filter([$osi, $joins], '  ', []));
    }

    public function testTheMostRecentlyUpdatedComesFirstAndANeverEditedCourseCountsFromItsCreation(): void
    {
        $old = $this->course('Ancien', [], '', '2026-09-01 10:00');
        $edited = $this->course('Modifié hier', [], '', '2026-10-02 08:00');
        $created = $this->course('Créé ce matin', []);

        self::assertEquals($created->getCreatedAt(), $created->getUpdatedAt());
        self::assertSame([$created, $edited, $old], OnlineCoursePublicPage::newestFirst([$old, $created, $edited]));
    }

    public function testByTitleIgnoresCaseAndAccents(): void
    {
        $model = $this->course('le modèle relationnel', []);
        $joins = $this->course('Les jointures SQL', []);
        $acl = $this->course('Écrire une ACL', []);
        $docker = $this->course('Docker', []);

        self::assertSame([$docker, $acl, $model, $joins], OnlineCoursePublicPage::byTitle([$joins, $model, $acl, $docker]));
    }

    public function testOneRowPerTagInAlphabeticalOrderEachByTitle(): void
    {
        $joins = $this->course('Les jointures SQL', ['SQL', 'SLAM']);
        $model = $this->course('Le modèle relationnel', ['SQL']);
        $acl = $this->course('Des droits SQL', ['SQL']);
        $osi = $this->course('Le modèle OSI', ['Réseau']);
        $untagged = $this->course('Divers', []);

        // Handed newest first, as the page loads them: the rows read them by title all the same.
        $rows = $this->page->groups([$joins, $model, $osi, $acl, $untagged]);

        self::assertSame(['Réseau', 'SLAM', 'SQL'], array_column($rows, 'label'));
        self::assertSame([$osi], $rows[0]['courses']);
        self::assertSame([$joins], $rows[1]['courses']);
        self::assertSame([$acl, $model, $joins], $rows[2]['courses']);
    }

    public function testTheSupportFilterKeepsTheCoursesCarryingThatKindOrATest(): void
    {
        $video = $this->course('Vidéo seule', [], kinds: [OnlineCourseMaterialKind::Video]);
        $both = $this->course('PDF et vidéo', [], kinds: [OnlineCourseMaterialKind::Pdf, OnlineCourseMaterialKind::Video]);
        $tested = $this->course('PDF testé', [], kinds: [OnlineCourseMaterialKind::Pdf], withTest: true);
        $all = [$video, $both, $tested];

        self::assertSame($all, OnlineCoursePublicPage::withSupport($all, ''));
        self::assertSame([$video, $both], OnlineCoursePublicPage::withSupport($all, 'video'));
        self::assertSame([$both, $tested], OnlineCoursePublicPage::withSupport($all, 'pdf'));
        self::assertSame([], OnlineCoursePublicPage::withSupport($all, 'interactive'));
        self::assertSame([$tested], OnlineCoursePublicPage::withSupport($all, OnlineCoursePublicPage::SUPPORT_TEST));
    }

    public function testAQuizWithNoQuestionIsNoTest(): void
    {
        $empty = $this->course('Quiz vide', [], kinds: [OnlineCourseMaterialKind::Pdf]);
        $empty->setQuizTemplate(new QuizTemplate($this->owner));

        self::assertSame([], OnlineCoursePublicPage::withSupport([$empty], OnlineCoursePublicPage::SUPPORT_TEST));
    }

    public function testTheFilterOffersTheKindsThePageHoldsInTheCataloguesOrder(): void
    {
        $courses = [
            $this->course('A', [], kinds: [OnlineCourseMaterialKind::Video, OnlineCourseMaterialKind::Pdf]),
            $this->course('B', [], kinds: [OnlineCourseMaterialKind::Interactive, OnlineCourseMaterialKind::Pdf]),
        ];

        self::assertSame([OnlineCourseMaterialKind::Interactive, OnlineCourseMaterialKind::Pdf, OnlineCourseMaterialKind::Video], OnlineCoursePublicPage::kindsOf($courses));
        self::assertSame([], OnlineCoursePublicPage::kindsOf([]));
    }

    public function testTwoSpellingsOfATagAreOneTag(): void
    {
        self::assertSame(OnlineCourseTag::normalize('Réseau'), OnlineCourseTag::normalize('  reseau '));
        self::assertSame('bloc 1', OnlineCourseTag::normalize('Bloc   1'));
    }

    /**
     * @param list<string>                   $tags
     * @param list<OnlineCourseMaterialKind> $kinds
     */
    private function course(string $title, array $tags, string $summary = '', ?string $updatedAt = null, array $kinds = [], bool $withTest = false): OnlineCourse
    {
        $course = new OnlineCourse($this->owner, $title, 'slug');
        $course->setSummary($summary);
        if (null !== $updatedAt) {
            (new \ReflectionProperty(OnlineCourse::class, 'updatedAt'))->setValue($course, new \DateTimeImmutable($updatedAt));
        }
        foreach ($tags as $tag) {
            $course->addTag(new OnlineCourseTag($this->owner, $tag));
        }
        foreach ($kinds as $kind) {
            $course->addMaterial(new OnlineCourseMaterial($course, $kind, $kind->value));
        }
        if ($withTest) {
            $quiz = new QuizTemplate($this->owner);
            $quiz->addQuestion(new QuizQuestion($quiz));
            $course->setQuizTemplate($quiz);
        }

        return $course;
    }
}
