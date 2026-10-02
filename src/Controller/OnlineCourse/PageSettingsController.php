<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Form\OnlineCoursePageType;
use App\Repository\OnlineCoursePageRepository;
use App\Repository\OnlineCourseRepository;
use App\Service\FormValue;
use App\Service\OnlineCourse\OnlineCourseHandleRefused;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Ma page » (design/validated/cours-en-ligne.md, §8): the address, the title and the
 * presentation of the teacher's own public page.
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
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        #[Target('app.library_content')] HtmlSanitizerInterface $sanitizer,
    ): Response {
        $user = $this->currentUser();
        $page = $pages->findOneByOwner($user);

        $form = $this->createForm(OnlineCoursePageType::class, [
            'handle' => $page?->getHandle() ?? '',
            'title' => $page?->getTitle() ?? $translator->trans('onlineCoursePageDefaultTitle', ['%name%' => $user->getDisplayName() ?? $user->getUserIdentifier()]),
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
                $page->setIntroduction('' === trim($introduction) ? null : $sanitizer->sanitize($introduction));
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
}
