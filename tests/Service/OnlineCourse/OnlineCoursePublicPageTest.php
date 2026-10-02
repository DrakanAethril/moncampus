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
 * What a teacher's public page shows of a list of courses: the filters narrow, the tags are counted
 * on the whole page, and the « par thème » view loses no course.
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

    public function testChipsCountTheWholePageAndMarkTheActiveOnes(): void
    {
        $courses = [$this->course('A', ['SQL', 'SLAM']), $this->course('B', ['SQL']), $this->course('C', [])];

        self::assertSame([
            ['label' => 'SLAM', 'key' => 'slam', 'count' => 1, 'active' => false],
            ['label' => 'SQL', 'key' => 'sql', 'count' => 2, 'active' => true],
        ], $this->page->chips($courses, ['sql']));
    }

    public function testByThemeACourseSitsUnderEachOfItsTagsAndAnUntaggedOneIsNotLost(): void
    {
        $joins = $this->course('Les jointures SQL', ['SQL', 'SLAM']);
        $untagged = $this->course('Divers', []);

        $groups = $this->page->groups([$joins, $untagged]);

        self::assertSame(['SLAM', 'SQL', ''], array_column($groups, 'label'));
        self::assertSame([$joins], $groups[0]['courses']);
        self::assertSame([$joins], $groups[1]['courses']);
        self::assertSame([$untagged], $groups[2]['courses']);
    }

    public function testTwoSpellingsOfATagAreOneTag(): void
    {
        self::assertSame(OnlineCourseTag::normalize('Réseau'), OnlineCourseTag::normalize('  reseau '));
        self::assertSame('bloc 1', OnlineCourseTag::normalize('Bloc   1'));
    }

    /**
     * @param list<string> $tags
     */
    private function course(string $title, array $tags, string $summary = ''): OnlineCourse
    {
        $course = new OnlineCourse($this->owner, $title, 'slug');
        $course->setSummary($summary);
        foreach ($tags as $tag) {
            $course->addTag(new OnlineCourseTag($this->owner, $tag));
        }

        return $course;
    }
}
