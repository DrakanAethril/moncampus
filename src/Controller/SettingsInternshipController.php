<?php

namespace App\Controller;

use App\Entity\InternshipBehaviorCriteria;
use App\Entity\InternshipBehaviorLevel;
use App\Entity\User;
use App\Form\InternshipBehaviorCriteriaType;
use App\Form\InternshipFormationCenterType;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The "Livret Alternant" settings area, reached via its own entry in the global "Paramètres"
// dropdown - kept as a sibling of SettingsStructureController for its two remaining tabs
// (formation_center/behavior). Niveaux de compétence used to be a third tab here, but that's a
// rarely-changes-between-years setting (like Configuration's other tabs), not something tied to
// this year's Livret Alternant content - it now lives in SettingsStructureController instead, see
// the skill-levels methods there.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsInternshipController extends AbstractController
{
    #[Route(path: '/settings/internship', name: 'app_settings_internship')]
    #[Route(path: '/settings/internship/formation-center', name: 'app_settings_internship_formation_center')]
    public function formationCenterTab(Request $request, EntityManagerInterface $entityManager, InternshipFormationCenterRepository $repository): Response
    {
        $formationCenter = $repository->getOrCreate();

        if (null === $formationCenter->getCreatedBy()) {
            $formationCenter->setCreatedBy($this->currentUser());
        }

        $form = $this->createForm(InternshipFormationCenterType::class, $formationCenter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formationCenter->setLastUpdatedBy($this->currentUser());
            $formationCenter->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'internshipFormationCenterUpdatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_formation_center');
        }

        return $this->render('settings/internship.html.twig', [
            'activeTab' => 'formation_center',
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/internship/behavior', name: 'app_settings_internship_behavior')]
    public function behaviorTab(): Response
    {
        return $this->render('settings/internship.html.twig', ['activeTab' => 'behavior']);
    }

    #[Route(path: '/settings/internship/behavior/new', name: 'app_settings_internship_behavior_new')]
    #[Route(path: '/settings/internship/behavior/{id}/edit', name: 'app_settings_internship_behavior_edit')]
    public function behaviorCriteriaForm(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        $criteria = $isEdit ? $this->findOrNotFound($repository, $id) : new InternshipBehaviorCriteria();

        if (!$isEdit) {
            for ($levelNumber = 1; $levelNumber <= 5; ++$levelNumber) {
                $criteria->addLevel(new InternshipBehaviorLevel($levelNumber));
            }
        }

        $form = $this->createForm(InternshipBehaviorCriteriaType::class, $criteria);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipBehaviorUpdatedFlashMessage' : 'internshipBehaviorCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_behavior');
        }

        return $this->render('settings/internship_behavior_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/internship/behavior/{id}/deactivate', name: 'app_settings_internship_behavior_deactivate', methods: ['POST'])]
    public function deactivateBehaviorCriteria(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, int $id): JsonResponse
    {
        $criteria = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $criteria->setInactiveDate(new \DateTimeImmutable());
        $criteria->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/internship/behavior/data', name: 'app_settings_internship_behavior_data')]
    public function behaviorCriteriaData(Request $request, InternshipBehaviorCriteriaRepository $repository): JsonResponse
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
                fn (InternshipBehaviorCriteria $criteria): array => [
                    'id' => $criteria->getId(),
                    'isInactive' => null !== $criteria->getInactiveDate(),
                    'label' => $criteria->getLabel(),
                    'orderIndex' => $criteria->getOrderIndex(),
                    'creationDate' => $criteria->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $criteria->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($criteria->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($criteria->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($criteria->getLastUpdatedBy()),
                    'lastUpdatedDate' => $criteria->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('internship_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
