<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Enum\Feature;
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
 * A teacher's public page, `/courses/{handle}` (design/validated/cours-en-ligne.md, §5): their
 * published courses, searched, filtered by tag or grouped by tag. Read without an account.
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
        $courses = $publicPage->filter($all, $search, $tagKeys);
        $byTheme = 'themes' === QueryValue::trimmed($request, 'view');

        // The teacher's learning paths, for a signed-in visitor the establishment lets follow them -
        // and nothing about them for anybody else: not a tab, not a count.
        $learningPaths = null !== $viewer && $features->isEnabled(Feature::LearningPaths) ? $paths->findPublishedForOwner($page->getOwner()) : [];
        $showPaths = [] !== $learningPaths && 'paths' === QueryValue::trimmed($request, 'tab');

        return $this->render('online_course/public/page.html.twig', [
            'page' => $page,
            'isOwner' => $isOwner,
            'total' => \count($all),
            'courses' => $courses,
            'groups' => $byTheme ? $publicPage->groups($courses) : [],
            'byTheme' => $byTheme,
            'chips' => $publicPage->chips($all, $tagKeys),
            'search' => $search,
            'tagKeys' => $tagKeys,
            'learningPaths' => $learningPaths,
            'showPaths' => $showPaths,
        ]);
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
