<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Repository\OnlineCourseRepository;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCoursePublicPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page of one course, `/courses/{handle}/{slug}` (design/validated/cours-en-ligne.md, §5): its
 * card, one tab per material, and the material itself read from the CDN. Read without an account.
 *
 * Each material has its own address - `/courses/{handle}/{slug}/{material}` - so a teacher can
 * point straight at the summary sheet or the video; with no material named, the page opens on the
 * first one in the author's order.
 *
 * Who may read is App\Security\Voter\OnlineCourseVoter's VIEW, a 404 otherwise: a draft is its
 * author's, shown to them here exactly as it will be, under a banner that says it is not online.
 * No App\Attribute\RequiresFeature, for the reason App\Controller\OnlineCourse\PublicPageController
 * gives.
 */
class PublicCourseController extends AbstractController
{
    use PublicCourseTrait;

    private const string SEGMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    #[Route(path: '/courses/{handle}/{slug}/{material}', name: 'app_public_courses_course', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT, 'material' => '[a-z0-9]+(?:[-_][a-z0-9]+)*'], defaults: ['material' => null], methods: ['GET'])]
    public function __invoke(string $handle, string $slug, ?string $material, Request $request, OnlineCoursePageHandles $handles, OnlineCourseRepository $courses, OnlineCoursePublicPage $publicPage, OnlineCourseContentOrigin $origin): Response
    {
        $page = $this->pageOrRedirect($handle, $request, $handles);
        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $course = $courses->findOneByOwnerAndSlug($page->getOwner(), $slug);
        if (null === $course || !$this->isGranted(OnlineCourseVoter::VIEW, $course)) {
            throw $this->createNotFoundException();
        }

        $current = null === $material ? ($course->getMaterials()->first() ?: null) : $course->findMaterialBySlug($material);
        if (null !== $material && null === $current) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->viewer();
        $live = $current?->getLive();

        return $this->render('online_course/public/course.html.twig', [
            'page' => $page,
            'course' => $course,
            'current' => $current,
            'currentUrl' => null === $live ? null : $origin->url($live),
            'isOwner' => null !== $viewer && $course->isOwnedBy($viewer),
            'canUnpublish' => $this->isGranted(OnlineCourseVoter::UNPUBLISH, $course),
            'siblings' => $this->siblings($course, $publicPage->all($page)),
        ]);
    }

    /**
     * « Du même auteur »: up to three other public courses, the ones sharing a tag first.
     *
     * @param list<OnlineCourse> $all
     *
     * @return list<OnlineCourse>
     */
    private function siblings(OnlineCourse $course, array $all): array
    {
        $others = array_values(array_filter($all, static fn (OnlineCourse $other): bool => $other !== $course));

        $shared = static function (OnlineCourse $other) use ($course): int {
            $count = 0;
            foreach ($other->getTags() as $tag) {
                $count += $course->hasTag($tag->getNormalizedLabel()) ? 1 : 0;
            }

            return $count;
        };

        usort($others, static fn (OnlineCourse $a, OnlineCourse $b): int => [$shared($b), $a->getTitle()] <=> [$shared($a), $b->getTitle()]);

        return \array_slice($others, 0, 3);
    }
}
