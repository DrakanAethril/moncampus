<?php

namespace App\Controller\Settings;

use App\Entity\LessonType;
use App\Form\LessonTypeType;
use App\Repository\LessonTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Types de cours ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class LessonTypeController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/lesson-types', name: 'app_settings_structure_lesson_types')]
    public function lessonTypesTab(): Response
    {
        return $this->renderTab('lesson_types');
    }

    #[Route(path: '/settings/structure/lesson-types/new', name: 'app_settings_structure_lesson_types_new')]
    #[Route(path: '/settings/structure/lesson-types/{id}/edit', name: 'app_settings_structure_lesson_types_edit')]
    public function lessonTypeForm(Request $request, EntityManagerInterface $entityManager, LessonTypeRepository $repository, ?int $id = null): Response
    {
        $lessonType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $lessonType;

        $form = $this->createForm(LessonTypeType::class, $lessonType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'lessonTypeUpdatedFlashMessage' : 'lessonTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_lesson_types');
        }

        return $this->render('settings/lesson_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/lesson-types/{id}/deactivate', name: 'app_settings_structure_lesson_types_deactivate', methods: ['POST'])]
    public function deactivateLessonType(Request $request, EntityManagerInterface $entityManager, LessonTypeRepository $repository, int $id): JsonResponse
    {
        $lessonType = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $lessonType->setInactiveDate(new \DateTimeImmutable());
        $lessonType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/lesson-types/data', name: 'app_settings_structure_lesson_types_data')]
    public function lessonTypesData(Request $request, LessonTypeRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (LessonType $lessonType): array => [
                    'id' => $lessonType->getId(),
                    'isInactive' => null !== $lessonType->getInactiveDate(),
                    'name' => $lessonType->getName(),
                    'agendaColor' => $lessonType->getAgendaColor(),
                    'defaultCost' => $lessonType->getDefaultCost() ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
