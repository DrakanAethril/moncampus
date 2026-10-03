<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseTag;
use App\Entity\User;
use App\Repository\OnlineCourseRepository;
use App\Service\OnlineCourse\OnlineCoursePublicPage;
use PHPUnit\Framework\TestCase;

/**
 * What a teacher's public page shows of a list of courses: the newest first, one row per tag in
 * alphabetical order, and filters that narrow.
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

    public function testOneRowPerTagInAlphabeticalOrderKeepingTheCoursesOrder(): void
    {
        $joins = $this->course('Les jointures SQL', ['SQL', 'SLAM']);
        $model = $this->course('Le modèle relationnel', ['SQL']);
        $osi = $this->course('Le modèle OSI', ['Réseau']);
        $untagged = $this->course('Divers', []);

        $rows = $this->page->groups([$model, $joins, $osi, $untagged]);

        self::assertSame(['Réseau', 'SLAM', 'SQL'], array_column($rows, 'label'));
        self::assertSame([$osi], $rows[0]['courses']);
        self::assertSame([$joins], $rows[1]['courses']);
        self::assertSame([$model, $joins], $rows[2]['courses']);
    }

    public function testTwoSpellingsOfATagAreOneTag(): void
    {
        self::assertSame(OnlineCourseTag::normalize('Réseau'), OnlineCourseTag::normalize('  reseau '));
        self::assertSame('bloc 1', OnlineCourseTag::normalize('Bloc   1'));
    }

    /**
     * @param list<string> $tags
     */
    private function course(string $title, array $tags, string $summary = '', ?string $updatedAt = null): OnlineCourse
    {
        $course = new OnlineCourse($this->owner, $title, 'slug');
        $course->setSummary($summary);
        if (null !== $updatedAt) {
            (new \ReflectionProperty(OnlineCourse::class, 'updatedAt'))->setValue($course, new \DateTimeImmutable($updatedAt));
        }
        foreach ($tags as $tag) {
            $course->addTag(new OnlineCourseTag($this->owner, $tag));
        }

        return $course;
    }
}
