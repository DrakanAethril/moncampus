<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use App\Entity\ProgramLessonTypeCost;
use App\Enum\FinancialItemSource;
use App\Form\ProgramFinancialItemType;
use App\Repository\LessonTypeRepository;
use App\Repository\ProgramFinancialItemRepository;
use App\Repository\ProgramLessonTypeCostRepository;
use App\Repository\ProgramRepository;
use App\Service\PostValue;
use App\Service\ProgramFinancialCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Paramétrage, onglet « Financier » : coûts par type de cours et lignes de budget. Tout l'onglet est derrière le drapeau financialManagementEnabled de la formation.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsFinancialController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/financial', name: 'app_program_settings_financial')]
    public function financialTab(int $id, ProgramRepository $repository, LessonTypeRepository $lessonTypeRepository, ProgramLessonTypeCostRepository $costRepository, ProgramFinancialCalculator $calculator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $lessonTypes = $lessonTypeRepository->findAllActiveOrderedByName();

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'financial',
            'lessonTypes' => $lessonTypes,
            'financialTotals' => $calculator->computeTotals($program),
            'overridesByLessonTypeId' => $costRepository->findCostMapForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/financial/items/new-lesson', name: 'app_program_settings_financial_items_new_lesson')]
    #[Route(path: '/programs/{id}/settings/financial/items/new-student', name: 'app_program_settings_financial_items_new_student')]
    #[Route(path: '/programs/{id}/settings/financial/items/new-manual', name: 'app_program_settings_financial_items_new_manual')]
    #[Route(path: '/programs/{id}/settings/financial/items/{itemId}/edit', name: 'app_program_settings_financial_items_edit')]
    public function financialItemForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramFinancialItemRepository $financialItemRepository, LessonTypeRepository $lessonTypeRepository, ProgramFinancialCalculator $calculator, ?int $itemId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $financialItem = null !== $itemId ? $this->findFinancialItemOrNotFound($financialItemRepository, $program, $itemId) : null;
        $isEdit = null !== $financialItem;

        if ($isEdit) {
            $source = $financialItem->getSource();
        } else {
            $source = match ($request->attributes->get('_route')) {
                'app_program_settings_financial_items_new_lesson' => FinancialItemSource::Lesson,
                'app_program_settings_financial_items_new_student' => FinancialItemSource::Student,
                'app_program_settings_financial_items_new_manual' => FinancialItemSource::Manual,
                default => throw $this->createNotFoundException(),
            };
        }

        $form = $this->createForm(ProgramFinancialItemType::class, $financialItem, ['program' => $program, 'source' => $source]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'financialItemUpdatedFlashMessage' : 'financialItemCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
        }

        return $this->render('program/financial_item_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'source' => $source,
            'costsByLessonTypeId' => FinancialItemSource::Lesson === $source
                ? $calculator->getEffectiveCostMap($program, $lessonTypeRepository->findAllActiveOrderedByName())
                : [],
        ]);
    }

    #[Route(path: '/programs/{id}/settings/financial/items/{itemId}/remove', name: 'app_program_settings_financial_items_remove', methods: ['POST'])]
    public function removeFinancialItem(int $id, int $itemId, Request $request, ProgramRepository $repository, ProgramFinancialItemRepository $financialItemRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $financialItem = $this->findFinancialItemOrNotFound($financialItemRepository, $program, $itemId);

        if (!$this->isCsrfTokenValid('program_settings_financial_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($financialItem);
        $entityManager->flush();

        $this->addFlash('success', 'financialItemRemovedFlashMessage');

        return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/financial/costs', name: 'app_program_settings_financial_costs', methods: ['POST'])]
    public function updateLessonTypeCosts(int $id, Request $request, ProgramRepository $repository, LessonTypeRepository $lessonTypeRepository, ProgramLessonTypeCostRepository $costRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());

        if (!$this->isCsrfTokenValid('program_settings_financial_costs', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submittedCosts = PostValue::all($request, 'costs');

        foreach ($lessonTypeRepository->findAllActiveOrderedByName() as $lessonType) {
            $raw = trim($this->submittedText($submittedCosts, $lessonType->getId()));
            $existingOverride = $costRepository->findOneForProgramAndLessonType($program, $lessonType);

            if ('' === $raw || !is_numeric($raw) || $raw < 0) {
                if ('' === $raw && null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setCost($raw);
            } else {
                $entityManager->persist(new ProgramLessonTypeCost($program, $lessonType, $raw));
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'lessonTypeCostsUpdatedFlashMessage');

        return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
    }

    private function findFinancialItemOrNotFound(ProgramFinancialItemRepository $repository, Program $program, int $itemId): ProgramFinancialItem
    {
        $financialItem = $repository->find($itemId) ?? throw $this->createNotFoundException();

        if ($financialItem->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $financialItem;
    }

    /**
     * One cell of a per-row override form, keyed by the row's own id. The values come straight off
     * a repeated field, so nothing guarantees a submitted key holds a string - a blank reads the
     * same as a row the form never sent, which both mean "no override".
     *
     * @param array<array-key, mixed> $submitted
     */
    private function submittedText(array $submitted, string|int $key): string
    {
        $value = $submitted[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
