<?php

namespace App\Controller\Settings;

use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\SchoolYear;
use App\Form\PeriodGroupType;
use App\Form\PeriodType as PeriodFormType;
use App\Repository\PeriodGroupRepository;
use App\Repository\PeriodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paramètres > Pédagogique, onglet « Groupes de périodes », et les périodes que chaque groupe contient.
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class PeriodGroupController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/period-groups', name: 'app_settings_structure_period_groups')]
    public function periodGroupsTab(): Response
    {
        return $this->renderTab('period_groups');
    }

    #[Route(path: '/settings/structure/period-groups/new', name: 'app_settings_structure_period_groups_new')]
    #[Route(path: '/settings/structure/period-groups/{id}/edit', name: 'app_settings_structure_period_groups_edit')]
    public function periodGroupForm(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, ?int $id = null): Response
    {
        $periodGroup = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $periodGroup;

        $form = $this->createForm(PeriodGroupType::class, $periodGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodGroupUpdatedFlashMessage' : 'periodGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_groups');
        }

        return $this->render('settings/period_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{id}/deactivate', name: 'app_settings_structure_period_groups_deactivate', methods: ['POST'])]
    public function deactivatePeriodGroup(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, int $id): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $periodGroup->setInactiveDate(new \DateTimeImmutable());
        $periodGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Clones a PeriodGroup and its active Periods only (deactivated/historical periods aren't
    // carried over) - kept on the same SchoolYear as the source; staff re-assign that afterward
    // via the normal edit form if duplicating into a new year. Navigates straight into the
    // duplicate's periods list (see the frontend's redirectUrl handling in performAction()) so
    // staff can immediately review/adjust dates rather than landing back on the flat group list.
    #[Route(path: '/settings/structure/period-groups/{id}/duplicate', name: 'app_settings_structure_period_groups_duplicate', methods: ['POST'])]
    public function duplicatePeriodGroup(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository, TranslatorInterface $translator, int $id): JsonResponse
    {
        $source = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $copy = new PeriodGroup(sprintf($translator->trans('periodGroupDuplicateNameFormat'), $source->getName()), $source->getSchoolYear());
        $copy->setCreatedBy($this->currentUser());
        $entityManager->persist($copy);

        foreach ($periodRepository->findAllActiveForPeriodGroup($source) as $period) {
            $periodCopy = new Period($period->getName(), $period->getStartDate(), $period->getEndDate(), $period->getType(), $copy);
            $periodCopy->setCreatedBy($this->currentUser());
            $entityManager->persist($periodCopy);
        }

        $entityManager->flush();

        $this->addFlash('success', 'periodGroupDuplicatedFlashMessage');

        return $this->json([
            'success' => true,
            'redirectUrl' => $this->generateUrl('app_settings_structure_period_groups_periods', ['groupId' => $copy->getId()]),
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods', name: 'app_settings_structure_period_groups_periods')]
    public function periodGroupPeriodsList(int $groupId, PeriodGroupRepository $repository): Response
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);

        return $this->render('settings/period_group_periods.html.twig', [
            'periodGroup' => $periodGroup,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/new', name: 'app_settings_structure_period_groups_periods_new')]
    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/{id}/edit', name: 'app_settings_structure_period_groups_periods_edit')]
    public function periodGroupPeriodForm(int $groupId, Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository, ?int $id = null): Response
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        $period = null !== $id ? $this->findPeriodOrNotFound($periodRepository, $periodGroup, $id) : null;
        $isEdit = null !== $period;

        $form = $this->createForm(PeriodFormType::class, $period, ['periodGroup' => $periodGroup]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodUpdatedFlashMessage' : 'periodCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_groups_periods', ['groupId' => $periodGroup->getId()]);
        }

        return $this->render('settings/period_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'periodGroup' => $periodGroup,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/{id}/deactivate', name: 'app_settings_structure_period_groups_periods_deactivate', methods: ['POST'])]
    public function deactivatePeriodGroupPeriod(int $groupId, int $id, Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        $period = $this->findPeriodOrNotFound($periodRepository, $periodGroup, $id);
        $this->assertValidDeactivateToken($request);

        $period->setInactiveDate(new \DateTimeImmutable());
        $period->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/period-groups/data', name: 'app_settings_structure_period_groups_data')]
    public function periodGroupsData(Request $request, PeriodGroupRepository $repository): JsonResponse
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
                fn (PeriodGroup $periodGroup): array => [
                    'id' => $periodGroup->getId(),
                    'isInactive' => null !== $periodGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column (see
                    // _period_groups_content.html.twig) - the default column render escapes it.
                    'name' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_settings_structure_period_groups_periods', ['groupId' => $periodGroup->getId()])),
                        htmlspecialchars($periodGroup->getName()),
                    ),
                    'schoolYearLabel' => sprintf('%s - %s', $periodGroup->getSchoolYear()->getStartDate()->format('Y'), $periodGroup->getSchoolYear()->getEndDate()->format('Y')),
                    'creationDate' => $periodGroup->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $periodGroup->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($periodGroup->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($periodGroup->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($periodGroup->getLastUpdatedBy()),
                    'lastUpdatedDate' => $periodGroup->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/data', name: 'app_settings_structure_period_groups_periods_data')]
    public function periodGroupPeriodsData(int $groupId, Request $request, PeriodGroupRepository $repository, PeriodRepository $periodRepository): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $periodRepository->countAllForPeriodGroup($periodGroup, null, $includeInactive);
        $filteredTotal = '' !== $search ? $periodRepository->countAllForPeriodGroup($periodGroup, $search, $includeInactive) : $total;
        $rows = $periodRepository->findPageForPeriodGroupOrderedByMostRecent($periodGroup, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Period $period): array => [
                    'id' => $period->getId(),
                    'isInactive' => null !== $period->getInactiveDate(),
                    'name' => $period->getName(),
                    'typeName' => $period->getType()?->getName() ?? '—',
                    'startDate' => $period->getStartDate()->format('d/m/Y'),
                    'endDate' => $period->getEndDate()->format('d/m/Y'),
                    'creationDate' => $period->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $period->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($period->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($period->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($period->getLastUpdatedBy()),
                    'lastUpdatedDate' => $period->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    private function findPeriodOrNotFound(PeriodRepository $repository, PeriodGroup $periodGroup, int $id): Period
    {
        $period = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($period->getPeriodGroup()?->getId() !== $periodGroup->getId()) {
            throw $this->createNotFoundException();
        }

        return $period;
    }
}
