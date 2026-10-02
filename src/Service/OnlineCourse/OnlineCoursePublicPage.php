<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Entity\OnlineCourseTag;
use App\Repository\OnlineCourseRepository;

/**
 * What a teacher's public page shows (design/validated/cours-en-ligne.md, §5): their public
 * courses, searched and filtered, and the tags to filter them by.
 *
 * One place answers « which courses are on this page », so the list, the tag counts and the
 * « par thème » view cannot disagree - and so the page exists exactly when list() has something to
 * show, which is what the controller's 404 reads.
 *
 * The filtering is done in PHP on purpose: a page holds one teacher's courses, a few dozen at most,
 * already loaded with their tags and materials to draw the cards.
 *
 * @phpstan-type TagChip array{label: string, key: string, count: int, active: bool}
 * @phpstan-type TagGroup array{label: string, key: string, courses: list<OnlineCourse>}
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
     * @return list<OnlineCourse>
     */
    public function all(OnlineCoursePage $page): array
    {
        return $this->courses->findPublicForOwner($page->getOwner());
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
     * The filter chips: every tag a public course of this page carries, with how many carry it.
     *
     * @param list<OnlineCourse> $courses the page's courses, unfiltered
     * @param list<string>       $activeKeys
     *
     * @return list<TagChip>
     */
    public function chips(array $courses, array $activeKeys): array
    {
        $chips = [];
        foreach ($courses as $course) {
            foreach ($course->getTags() as $tag) {
                $key = $tag->getNormalizedLabel();
                $chips[$key] ??= ['label' => $tag->getLabel(), 'key' => $key, 'count' => 0, 'active' => \in_array($key, $activeKeys, true)];
                ++$chips[$key]['count'];
            }
        }

        uasort($chips, static fn (array $a, array $b): int => strcoll($a['key'], $b['key']));

        return array_values($chips);
    }

    /**
     * « Par thème »: one row per tag. A course with two tags sits in two rows, and a course with
     * none sits in a last, unnamed one - leaving it out would make it unreachable from that view.
     *
     * @param list<OnlineCourse> $courses
     *
     * @return list<TagGroup>
     */
    public function groups(array $courses): array
    {
        $groups = [];
        $untagged = [];

        foreach ($courses as $course) {
            if ($course->getTags()->isEmpty()) {
                $untagged[] = $course;

                continue;
            }
            foreach ($course->getTags() as $tag) {
                $key = $tag->getNormalizedLabel();
                $groups[$key] ??= ['label' => $tag->getLabel(), 'key' => $key, 'courses' => []];
                $groups[$key]['courses'][] = $course;
            }
        }

        uasort($groups, static fn (array $a, array $b): int => strcoll($a['key'], $b['key']));
        $groups = array_values($groups);

        if ([] !== $untagged) {
            $groups[] = ['label' => '', 'key' => '', 'courses' => $untagged];
        }

        return $groups;
    }
}
