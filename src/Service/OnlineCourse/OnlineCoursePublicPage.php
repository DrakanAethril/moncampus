<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Entity\OnlineCourseTag;
use App\Repository\OnlineCourseRepository;

/**
 * What a teacher's public page shows (design/validated/cours-en-ligne.md, §5): their public
 * courses, the most recently updated first, in a row per tag, searched and filtered.
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
     * created counts from its creation, which is what `updatedAt` holds until then. Every row and
     * every list of the page keeps this order.
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
     * The rows of the page: one per tag, in alphabetical order, each keeping the order of the
     * courses it is given. A course with two tags sits in two rows. A course with none sits in no
     * row - the « récemment mis à jour » row above them all holds every course of the page.
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

        return array_values($groups);
    }
}
