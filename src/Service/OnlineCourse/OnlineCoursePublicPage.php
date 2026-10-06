<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Entity\OnlineCourseTag;
use App\Enum\OnlineCourseMaterialKind;
use App\Repository\OnlineCourseRepository;

/**
 * What a teacher's public page shows (design/validated/cours-en-ligne.md, §5): their public
 * courses, the most recently updated first, in a row per tag sorted by title, searched and
 * filtered by the nature of their materials.
 *
 * One place answers « which courses are on this page », so the rows, their counts and the page of
 * one row cannot disagree - and so the page exists exactly when all() has something to show, which
 * is what the controller's 404 reads.
 *
 * The sorting and filtering are done in PHP on purpose: a page holds one teacher's courses, a few
 * dozen at most, already loaded with their tags and materials to draw the cards.
 *
 * @phpstan-type TagRow array{label: string, key: string, courses: list<OnlineCourse>}
 */
class OnlineCoursePublicPage
{
    /** The support filter's value for « the courses that carry a test », beside the material kinds. */
    public const SUPPORT_TEST = 'test';

    public function __construct(
        private readonly OnlineCourseRepository $courses,
    ) {
    }

    public function isOnline(OnlineCoursePage $page): bool
    {
        return $this->courses->countPublicForOwner($page->getOwner()) > 0;
    }

    /**
     * The page's courses, the most recently updated first - a course never edited since it was
     * created counts from its creation, which is what `updatedAt` holds until then. The « récemment
     * mis à jour » row and its own list keep this order; the tag rows read their courses by title.
     *
     * @return list<OnlineCourse>
     */
    public function all(OnlineCoursePage $page): array
    {
        return self::newestFirst($this->courses->findPublicForOwner($page->getOwner()));
    }

    /**
     * @param list<OnlineCourse> $courses
     *
     * @return list<OnlineCourse>
     */
    public static function newestFirst(array $courses): array
    {
        usort($courses, static fn (OnlineCourse $a, OnlineCourse $b): int => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);

        return $courses;
    }

    /**
     * The courses by title, whatever the case and the accents - the order of a tag's own page,
     * where the reader looks a course up rather than watches what moved.
     *
     * @param list<OnlineCourse> $courses
     *
     * @return list<OnlineCourse>
     */
    public static function byTitle(array $courses): array
    {
        usort($courses, static fn (OnlineCourse $a, OnlineCourse $b): int => [OnlineCourseTag::normalize($a->getTitle()), $a->getId()] <=> [OnlineCourseTag::normalize($b->getTitle()), $b->getId()]);

        return $courses;
    }

    /**
     * The courses that carry **every** tag asked for and match the search. A tag the author does
     * not have filters on nothing rather than on everything: a link to a tag that has since been
     * renamed must not silently show the whole page as if it were the answer.
     *
     * @param list<OnlineCourse> $courses
     * @param list<string>       $tagKeys normalized labels
     *
     * @return list<OnlineCourse>
     */
    public function filter(array $courses, string $search, array $tagKeys): array
    {
        $needle = OnlineCourseTag::normalize($search);

        return array_values(array_filter($courses, static function (OnlineCourse $course) use ($needle, $tagKeys): bool {
            foreach ($tagKeys as $key) {
                if (!$course->hasTag($key)) {
                    return false;
                }
            }

            if ('' === $needle) {
                return true;
            }

            $haystack = $course->getTitle().' '.$course->getSummary();
            foreach ($course->getTags() as $tag) {
                $haystack .= ' '.$tag->getLabel();
            }

            return str_contains(OnlineCourseTag::normalize($haystack), $needle);
        }));
    }

    /**
     * The courses that carry the support asked for: a material of that kind, or a test for
     * SUPPORT_TEST. An empty support is « Tous » and keeps every course.
     *
     * @param list<OnlineCourse> $courses
     *
     * @return list<OnlineCourse>
     */
    public static function withSupport(array $courses, string $support): array
    {
        if ('' === $support) {
            return $courses;
        }

        return array_values(array_filter($courses, static function (OnlineCourse $course) use ($support): bool {
            if (self::SUPPORT_TEST === $support) {
                return $course->hasTest();
            }

            foreach ($course->getMaterials() as $material) {
                if ($material->getKind()->value === $support) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The material kinds the courses carry, in the catalogue's order - the support filter offers
     * these and no other, so that no choice of it answers an empty page.
     *
     * @param list<OnlineCourse> $courses
     *
     * @return list<OnlineCourseMaterialKind>
     */
    public static function kindsOf(array $courses): array
    {
        $present = [];
        foreach ($courses as $course) {
            foreach ($course->getMaterials() as $material) {
                $present[$material->getKind()->value] = true;
            }
        }

        return array_values(array_filter(OnlineCourseMaterialKind::cases(), static fn (OnlineCourseMaterialKind $kind): bool => isset($present[$kind->value])));
    }

    /**
     * The rows of the page: one per tag, in alphabetical order, each holding its courses by title -
     * a row is where a reader looks a course of that subject up, like the tag's own page; what
     * moved is the « récemment mis à jour » row's business. A course with two tags sits in two
     * rows. A course with none sits in no row - the « récemment mis à jour » row above them all
     * holds every course of the page.
     *
     * @param list<OnlineCourse> $courses
     *
     * @return list<TagRow>
     */
    public function groups(array $courses): array
    {
        $groups = [];

        foreach ($courses as $course) {
            foreach ($course->getTags() as $tag) {
                $key = $tag->getNormalizedLabel();
                $groups[$key] ??= ['label' => $tag->getLabel(), 'key' => $key, 'courses' => []];
                $groups[$key]['courses'][] = $course;
            }
        }

        uasort($groups, static fn (array $a, array $b): int => strcoll($a['key'], $b['key']));
        foreach ($groups as $key => $group) {
            $groups[$key]['courses'] = self::byTitle($group['courses']);
        }

        return array_values($groups);
    }
}
