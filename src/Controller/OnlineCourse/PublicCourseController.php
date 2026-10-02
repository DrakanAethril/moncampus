<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterial;
use App\Entity\OnlineCoursePage;
use App\Repository\OnlineCourseRepository;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCoursePublicPage;
use Psr\Log\LoggerInterface;
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
 * first one in the author's order. `…/{material}/play` is the same material **alone in the whole
 * window** (§7): the requirement is that a dynamic material opens full page although the CDN
 * serves it, so the page is nothing but its frame.
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

    public function __construct(
        private readonly OnlineCoursePageHandles $handles,
        private readonly OnlineCourseRepository $courses,
        private readonly OnlineCourseContentOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/courses/{handle}/{slug}/{material}', name: 'app_public_courses_course', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT, 'material' => self::SEGMENT], defaults: ['material' => null], methods: ['GET'])]
    public function show(string $handle, string $slug, ?string $material, Request $request, OnlineCoursePublicPage $publicPage): Response
    {
        $found = $this->find($handle, $slug, $material, $request);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$page, $course, $current] = $found;
        $viewer = $this->viewer();
        $live = $current?->getLive();

        return $this->render('online_course/public/course.html.twig', [
            'page' => $page,
            'course' => $course,
            'current' => $current,
            'currentUrl' => null === $live ? null : $this->origin->url($live),
            'frameAllowed' => null === $current || $this->frameAllowed($current, $request),
            'isOwner' => null !== $viewer && $course->isOwnedBy($viewer),
            'canUnpublish' => $this->isGranted(OnlineCourseVoter::UNPUBLISH, $course),
            'siblings' => $this->siblings($course, $publicPage->all($page)),
        ]);
    }

    /**
     * The material alone in the window. Only what is shown in a frame has a full page - an
     * interactive course, a PDF: a video already has the browser's own full screen.
     */
    #[Route(path: '/courses/{handle}/{slug}/{material}/play', name: 'app_public_courses_play', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT, 'material' => self::SEGMENT], methods: ['GET'])]
    public function play(string $handle, string $slug, string $material, Request $request): Response
    {
        $found = $this->find($handle, $slug, $material, $request);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$page, $course, $current] = $found;
        $live = $current?->getLive();
        if (null === $current || null === $live || !($current->getKind()->isBundle() || $current->getKind()->isDocument())) {
            throw $this->createNotFoundException();
        }

        return $this->render('online_course/public/play.html.twig', [
            'page' => $page,
            'course' => $course,
            'material' => $current,
            'url' => $this->origin->url($live),
            'frameAllowed' => $this->frameAllowed($current, $request),
            'backUrl' => $this->generateUrl('app_public_courses_course', ['handle' => $page->getHandle(), 'slug' => $course->getSlug(), 'material' => $current->getSlug()]),
        ]);
    }

    /**
     * @return array{OnlineCoursePage, OnlineCourse, ?OnlineCourseMaterial}|RedirectResponse
     */
    private function find(string $handle, string $slug, ?string $material, Request $request): array|RedirectResponse
    {
        $page = $this->pageOrRedirect($handle, $request, $this->handles);
        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $course = $this->courses->findOneByOwnerAndSlug($page->getOwner(), $slug);
        if (null === $course || !$this->isGranted(OnlineCourseVoter::VIEW, $course)) {
            throw $this->createNotFoundException();
        }

        $current = null === $material ? ($course->getMaterials()->first() ?: null) : $course->findMaterialBySlug($material);
        if (null !== $material && null === $current) {
            throw $this->createNotFoundException();
        }

        return [$page, $course, $current];
    }

    /**
     * An interactive course is drawn only when the CDN is another origin than the application -
     * that difference is the whole isolation of its JavaScript. A deployment where it is not is a
     * misconfiguration somebody has to hear about, so it is logged at error level, and the reader
     * is told the course cannot be shown rather than shown a frame that would run as the platform.
     */
    private function frameAllowed(OnlineCourseMaterial $material, Request $request): bool
    {
        if (!$material->getKind()->isBundle() || $this->origin->isIsolatedFrom($request->getHost())) {
            return true;
        }

        $this->logger->error('An interactive course was not drawn: the content origin is the application\'s own host, so its scripts would not be isolated.', [
            'material' => $material->getId(),
            'host' => $request->getHost(),
        ]);

        return false;
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
