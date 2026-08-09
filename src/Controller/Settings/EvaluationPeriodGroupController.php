<?php

namespace App\Controller\Settings;

use App\Entity\EvaluationPeriodGroup;
use App\Entity\PeriodGroup;
use App\Form\EvaluationPeriodGroupType;
use App\Repository\EvaluationPeriodGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Pédagogique, onglet « Groupes de périodes d'évaluation ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class EvaluationPeriodGroupController extends AbstractController
{
    use SettingsTabTrait;

    // Unlike every other tab here, this one's list isn't DataTables-backed (see
    // EvaluationPeriodGroupRepository's docblock) - the whole thing is rendered from one query,
    // passed straight into the tab shell.
    #[Route(path: '/settings/structure/evaluation-period-groups', name: 'app_settings_structure_evaluation_period_groups')]
    public function evaluationPeriodGroupsTab(EvaluationPeriodGroupRepository $repository): Response
    {
        return $this->render('settings/'.self::TAB_GROUPS['evaluation_period_groups'].'.html.twig', [
            'activeTab' => 'evaluation_period_groups',
            'evaluationPeriodGroups' => $repository->findAllOrderedByName(true),
        ]);
    }

    // Group + its periods are edited together on one form (EvaluationPeriodGroupType's 'periods'
    // CollectionType) rather than the drill-in periods-list page PeriodGroup uses above - the
    // design (12b) shows every entry inline with a single Enregistrer, not a separate CRUD screen
    // per entry.
    #[Route(path: '/settings/structure/evaluation-period-groups/new', name: 'app_settings_structure_evaluation_period_groups_new')]
    #[Route(path: '/settings/structure/evaluation-period-groups/{id}/edit', name: 'app_settings_structure_evaluation_period_groups_edit')]
    public function evaluationPeriodGroupForm(Request $request, EntityManagerInterface $entityManager, EvaluationPeriodGroupRepository $repository, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        $evaluationPeriodGroup = $isEdit ? $this->findOrNotFound($repository, $id) : new EvaluationPeriodGroup('');

        $form = $this->createForm(EvaluationPeriodGroupType::class, $evaluationPeriodGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'evaluationPeriodGroupUpdatedFlashMessage' : 'evaluationPeriodGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_evaluation_period_groups');
        }

        return $this->render('settings/evaluation_period_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/evaluation-period-groups/{id}/deactivate', name: 'app_settings_structure_evaluation_period_groups_deactivate', methods: ['POST'])]
    public function deactivateEvaluationPeriodGroup(Request $request, EntityManagerInterface $entityManager, EvaluationPeriodGroupRepository $repository, int $id): JsonResponse
    {
        $evaluationPeriodGroup = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $evaluationPeriodGroup->setInactiveDate(new \DateTimeImmutable());
        $evaluationPeriodGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Unlike every other tab's deactivate-only lifecycle, the design (12a) shows a "Réactiver"
    // action for this list - clears the same inactiveDate/inactivatedBy pair deactivate() stamps.
    #[Route(path: '/settings/structure/evaluation-period-groups/{id}/reactivate', name: 'app_settings_structure_evaluation_period_groups_reactivate', methods: ['POST'])]
    public function reactivateEvaluationPeriodGroup(Request $request, EntityManagerInterface $entityManager, EvaluationPeriodGroupRepository $repository, int $id): JsonResponse
    {
        $evaluationPeriodGroup = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $evaluationPeriodGroup->setInactiveDate(null);
        $evaluationPeriodGroup->setInactivatedBy(null);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }
}
