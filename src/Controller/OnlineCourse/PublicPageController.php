<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Enum\Feature;
use App\Enum\OnlineCourseMaterialKind;
use App\Repository\LearningPathRepository;
use App\Security\FeatureAccess;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCoursePublicPage;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A teacher's public page, `/courses/{handle}` (design/validated/cours-en-ligne.md, §5): under the
 * teacher's banner, their published courses in rows - the most recently updated first, then one
 * row per tag in alphabetical order, its courses by title. Read without an account.
 *
 * Each row's « Voir tout » is the same address with a query: `?tag=sql` for a tag's courses,
 * `?view=recent` for all of them; a search is `?q=`. Those three show a full list instead of the
 * rows, so every state of the page still has an address that can be handed out. A tag's list is
 * sorted by title, or by last change with `&sort=modified`. `?support=video` (a material kind, or
 * `test`) narrows the rows and every list alike, and travels with all of them.
 *
 * **There is deliberately nothing above it.** No route lists the teachers and `/courses` alone
 * matches nothing: a common catalogue was proposed and refused, and a page is reached by the link
 * its teacher hands out.
 *
 * It carries no App\Attribute\RequiresFeature, like the sign-in screen: a visitor has no role to
 * resolve a feature against. What makes the page exist is a published course - an empty page
 * answers 404 to everybody but its owner, who sees it as it will be.
 */
class PublicPageController extends AbstractController
{
    use PublicCourseTrait;

    #[Route(path: '/courses/{handle}', name: 'app_public_courses_page', requirements: ['handle' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function __invoke(string $handle, Request $request, OnlineCoursePageHandles $handles, OnlineCoursePublicPage $publicPage, LearningPathRepository $paths, FeatureAccess $features): Response
    {
        $page = $this->pageOrRedirect($handle, $request, $handles);
        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $viewer = $this->viewer();
        $isOwner = null !== $viewer && $page->isOwnedBy($viewer);
        $all = $publicPage->all($page);

        if ([] === $all && !$isOwner) {
            throw $this->createNotFoundException();
        }

        $search = QueryValue::trimmed($request, 'q');
        $tagKeys = self::tagKeys($request);
        $support = self::support($request);
        $shown = OnlineCoursePublicPage::withSupport($all, $support);
        $rows = $publicPage->groups($shown);
        $showRecent = 'recent' === QueryValue::trimmed($request, 'view');
        $listed = '' !== $search || [] !== $tagKeys || $showRecent;
        // A tag's own page, and it alone, may be sorted by last change instead of title: the tag
        // rows hold their courses by title, « Récemment mis à jour » keeps the newest first.
        $sortable = '' === $search && [] !== $tagKeys;
        $sort = $sortable && 'modified' === QueryValue::trimmed($request, 'sort') ? 'modified' : 'name';
        $courses = $listed ? $publicPage->filter($shown, $search, $tagKeys) : [];
        if ($sortable && 'name' === $sort) {
            $courses = OnlineCoursePublicPage::byTitle($courses);
        }

        // The teacher's learning paths, for a signed-in visitor the establishment lets follow them -
        // and nothing about them for anybody else: not a tab, not a count.
        $learningPaths = null !== $viewer && $features->isEnabled(Feature::LearningPaths) ? $paths->findPublishedForOwner($page->getOwner()) : [];
        $showPaths = [] !== $learningPaths && 'paths' === QueryValue::trimmed($request, 'tab');

        return $this->render('online_course/public/page.html.twig', [
            'page' => $page,
            'isOwner' => $isOwner,
            'total' => \count($all),
            'shown' => $shown,
            'rows' => $rows,
            'support' => $support,
            'supportKind' => OnlineCourseMaterialKind::tryFrom($support),
            // The filter offers what the page holds - a choice that answers nothing is not offered.
            'supportKinds' => OnlineCoursePublicPage::kindsOf($all),
            'supportTest' => [] !== OnlineCoursePublicPage::withSupport($all, OnlineCoursePublicPage::SUPPORT_TEST),
            'listed' => $listed,
            'courses' => $courses,
            'sortable' => $sortable,
            'sort' => $sort,
            // What the list is named after: the tags asked for, by the labels the rows show - read
            // on every course, so that a support filter leaving a tag empty does not lose its name.
            'listedTags' => array_values(array_filter($publicPage->groups($all), static fn (array $row): bool => \in_array($row['key'], $tagKeys, true))),
            'search' => $search,
            'tagKeys' => $tagKeys,
            'learningPaths' => $learningPaths,
            'showPaths' => $showPaths,
        ]);
    }

    /**
     * The support asked for: a material kind's value or `test`. Anything else - a kind since
     * removed, a link typed by hand - is « Tous » rather than an empty page.
     */
    private static function support(Request $request): string
    {
        $support = QueryValue::trimmed($request, 'support');

        return OnlineCoursePublicPage::SUPPORT_TEST === $support || null !== OnlineCourseMaterialKind::tryFrom($support) ? $support : '';
    }

    /**
     * The tags asked for, as `?tag[]=sql&tag[]=slam` or a lone `?tag=sql` - a link typed by hand
     * writes the second and must not be answered a 400.
     *
     * @return list<string>
     */
    private static function tagKeys(Request $request): array
    {
        $raw = QueryValue::all($request, 'tag');
        if ([] === $raw) {
            $single = QueryValue::trimmed($request, 'tag');
            $raw = '' === $single ? [] : [$single];
        }

        $keys = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $keys[] = mb_strtolower(trim($value));
            }
        }

        return array_values(array_unique($keys));
    }
}
