<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterial;
use App\Enum\Feature;
use App\Enum\OnlineCourseMaterialKind;
use App\Form\OnlineCourseMaterialType;
use App\Repository\OnlineCourseRepository;
use App\Service\FormValue;
use App\Service\JsonRequestPayload;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use App\Service\StagedUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The materials of an online course, from its author's card (design/validated/cours-en-ligne.md,
 * §8): add one of a given nature, replace its file, go back to the revision before, remove it,
 * and decide the order of the public page's tabs.
 *
 * Each of them is the author's own gesture on their own course - App\Security\Voter\OnlineCourseVoter's
 * EDIT, a 404 for anybody else. The rules about the files themselves are not here: they are
 * App\Service\OnlineCourse\OnlineCourseMaterialStore's, which the Claude connector writes through too.
 */
#[RequiresFeature(Feature::OnlineCourses)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class MaterialController extends AbstractController
{
    use OnlineCourseAuthoringTrait;

    #[Route(path: '/tools/online-courses/{id}/materials/new/{kind}', name: 'app_online_courses_material_new', requirements: ['id' => '\d+', 'kind' => '[a-z_]+'], methods: ['GET', 'POST'])]
    public function new(int $id, string $kind, Request $request, OnlineCourseRepository $courses, OnlineCourseMaterialStore $store, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $course = $this->findCourse($id, $courses);
        $kind = OnlineCourseMaterialKind::tryFrom($kind);
        if (null === $kind || !\in_array($kind, AuthoringController::offeredKinds(), true)) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(OnlineCourseMaterialType::class, null, ['kind' => $kind]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            if ($file instanceof UploadedFile || $file instanceof StagedUpload || $file instanceof FileLibraryNode) {
                try {
                    $store->add($course, $kind, $file, FormValue::trimmed($form, 'label'));
                    $entityManager->flush();
                    $this->addFlash('success', 'onlineCourseMaterialAddedFlashMessage');

                    return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
                } catch (OnlineCourseMaterialRefused $refused) {
                    $form->get('file')->addError(new FormError($translator->trans($refused->getMessage(), $refused->parameters)));
                }
            }
        }

        return $this->render('online_course/authoring/material.html.twig', [
            'form' => $form,
            'course' => $course,
            'kind' => $kind,
            'material' => null,
        ]);
    }

    #[Route(path: '/tools/online-courses/{id}/materials/{materialId}/replace', name: 'app_online_courses_material_replace', requirements: ['id' => '\d+', 'materialId' => '\d+'], methods: ['GET', 'POST'])]
    public function replace(int $id, int $materialId, Request $request, OnlineCourseRepository $courses, OnlineCourseMaterialStore $store, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $course = $this->findCourse($id, $courses);
        $material = $this->findMaterial($course, $materialId);

        $form = $this->createForm(OnlineCourseMaterialType::class, null, ['kind' => $material->getKind(), 'with_label' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            if ($file instanceof UploadedFile || $file instanceof StagedUpload || $file instanceof FileLibraryNode) {
                try {
                    $store->replace($material, $file);
                    $entityManager->flush();
                    $this->addFlash('success', 'onlineCourseMaterialReplacedFlashMessage');

                    return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
                } catch (OnlineCourseMaterialRefused $refused) {
                    $form->get('file')->addError(new FormError($translator->trans($refused->getMessage(), $refused->parameters)));
                }
            }
        }

        return $this->render('online_course/authoring/material.html.twig', [
            'form' => $form,
            'course' => $course,
            'kind' => $material->getKind(),
            'material' => $material,
        ]);
    }

    #[Route(path: '/tools/online-courses/{id}/materials/{materialId}/label', name: 'app_online_courses_material_label', requirements: ['id' => '\d+', 'materialId' => '\d+'], methods: ['POST'])]
    public function label(int $id, int $materialId, Request $request, OnlineCourseRepository $courses, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request, AuthoringController::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses);
        $this->findMaterial($course, $materialId)->setLabel($request->request->getString('label'));
        $course->touch();
        $entityManager->flush();

        return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
    }

    #[Route(path: '/tools/online-courses/{id}/materials/{materialId}/restore', name: 'app_online_courses_material_restore', requirements: ['id' => '\d+', 'materialId' => '\d+'], methods: ['POST'])]
    public function restore(int $id, int $materialId, Request $request, OnlineCourseRepository $courses, OnlineCourseMaterialStore $store, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request, AuthoringController::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses);

        if ($store->switchToOtherRevision($this->findMaterial($course, $materialId))) {
            $entityManager->flush();
            $this->addFlash('success', 'onlineCourseMaterialRestoredFlashMessage');
        }

        return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
    }

    #[Route(path: '/tools/online-courses/{id}/materials/{materialId}/remove', name: 'app_online_courses_material_remove', requirements: ['id' => '\d+', 'materialId' => '\d+'], methods: ['POST'])]
    public function remove(int $id, int $materialId, Request $request, OnlineCourseRepository $courses, OnlineCourseMaterialStore $store, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request, AuthoringController::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses);
        $material = $this->findMaterial($course, $materialId);

        // A course that is online keeps at least one material: an empty course page is not a state
        // a reader should ever land on, and taking the course offline first is one click away.
        if ($course->isPublished() && 1 === $course->getMaterials()->count()) {
            $this->addFlash('error', 'onlineCourseMaterialLastOneFlashMessage');

            return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
        }

        $store->remove($material);
        $entityManager->flush();
        $this->addFlash('success', 'onlineCourseMaterialRemovedFlashMessage');

        return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
    }

    /**
     * The order of the tabs, sent whole by the drag handle (assets/controllers/sortable_reorder_controller.js):
     * a JSON list of ids, the token in a header.
     */
    #[Route(path: '/tools/online-courses/{id}/materials/order', name: 'app_online_courses_material_order', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function order(int $id, Request $request, OnlineCourseRepository $courses, OnlineCourseMaterialStore $store, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid(AuthoringController::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $course = $this->findCourse($id, $courses);
        $store->reorder($course, JsonRequestPayload::fromRequest($request)->ids('ids'));
        $entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function findMaterial(OnlineCourse $course, int $materialId): OnlineCourseMaterial
    {
        foreach ($course->getMaterials() as $material) {
            if ($material->getId() === $materialId) {
                return $material;
            }
        }

        throw $this->createNotFoundException();
    }
}
