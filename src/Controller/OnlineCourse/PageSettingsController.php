<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Entity\OnlineCoursePage;
use App\Enum\Feature;
use App\Form\OnlineCoursePageType;
use App\Repository\OnlineCoursePageRepository;
use App\Repository\OnlineCourseRepository;
use App\Service\FormValue;
use App\Service\OnlineCourse\OnlineCourseHandleRefused;
use App\Service\OnlineCourse\OnlineCourseImageStore;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\StagedUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Ma page » (design/validated/cours-en-ligne.md, §8): the address, the banner (title, colours,
 * picture, height) and the presentation of the teacher's own public page.
 *
 * The address may be changed at any time, before and during diffusion - that is the user's rule,
 * and the screen says what it costs: nothing, the address being left keeps redirecting
 * (App\Service\OnlineCourse\OnlineCoursePageHandles).
 */
#[RequiresFeature(Feature::OnlineCourses)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class PageSettingsController extends AbstractController
{
    use OnlineCourseAuthoringTrait;

    #[Route(path: '/tools/online-courses/page', name: 'app_online_courses_page', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        OnlineCoursePageRepository $pages,
        OnlineCourseRepository $courses,
        OnlineCoursePageHandles $handles,
        OnlineCourseImageStore $images,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        #[Target('app.library_content')] HtmlSanitizerInterface $sanitizer,
    ): Response {
        $user = $this->currentUser();
        $page = $pages->findOneByOwner($user);

        $form = $this->createForm(OnlineCoursePageType::class, [
            'handle' => $page?->getHandle() ?? '',
            'title' => $page?->getTitle() ?? $translator->trans('onlineCoursePageDefaultTitle', ['%name%' => $user->getDisplayName() ?? $user->getUserIdentifier()]),
            'titleColor' => $page?->getTitleColor() ?? OnlineCoursePage::DEFAULT_TITLE_COLOR,
            'bannerColor' => $page?->getBannerColor() ?? OnlineCoursePage::DEFAULT_BANNER_COLOR,
            'bannerHeight' => $page?->getBannerHeight() ?? OnlineCoursePage::DEFAULT_BANNER_HEIGHT,
            'introduction' => $page?->getIntroduction(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = FormValue::trimmed($form, 'title');
            $introduction = FormValue::string($form, 'introduction');

            try {
                if (null === $page) {
                    $page = $handles->create($user, FormValue::trimmed($form, 'handle'), $title);
                } else {
                    $handles->change($page, FormValue::trimmed($form, 'handle'));
                    $page->setTitle($title);
                }
                $page->setTitleColor(FormValue::string($form, 'titleColor'));
                $page->setBannerColor(FormValue::string($form, 'bannerColor'));
                $page->setBannerHeight(FormValue::int($form, 'bannerHeight', OnlineCoursePage::DEFAULT_BANNER_HEIGHT));
                $page->setIntroduction('' === trim($introduction) ? null : $sanitizer->sanitize($introduction));
                $entityManager->flush();

                $this->applyBannerImage($form, $page, $images, $translator);
                $entityManager->flush();

                $this->addFlash('success', 'onlineCoursePageSavedFlashMessage');

                return $this->redirectToRoute('app_online_courses_page');
            } catch (OnlineCourseHandleRefused $refused) {
                $form->get('handle')->addError(new FormError($translator->trans($refused->getMessage())));
                if (null !== $page && null !== $page->getId()) {
                    $entityManager->refresh($page);
                } else {
                    $page = null;
                }
            }
        }

        return $this->render('online_course/authoring/page.html.twig', [
            'form' => $form,
            'page' => $page,
            'formerHandles' => null === $page ? [] : $handles->formerHandles($page),
            'publicCount' => $courses->countPublicForOwner($user),
        ]);
    }

    /**
     * The banner picture chosen - or removed. A refusal is a flash, like a course's picture: the
     * field already holds the type and the size, so only a library file the picker could not sniff
     * gets here, and the rest of the page is still worth saving.
     *
     * @param FormInterface<mixed> $form
     */
    private function applyBannerImage(FormInterface $form, OnlineCoursePage $page, OnlineCourseImageStore $images, TranslatorInterface $translator): void
    {
        $image = $form->get('bannerImage')->getData();

        if ($image instanceof StagedUpload || $image instanceof FileLibraryNode) {
            try {
                $images->setPageBanner($page, $image);
            } catch (OnlineCourseMaterialRefused $refused) {
                $this->addFlash('error', $translator->trans($refused->getMessage(), $refused->parameters));
            }
        } elseif (true === $form->get('removeBannerImage')->getData()) {
            $images->removePageBanner($page);
        }
    }
}
