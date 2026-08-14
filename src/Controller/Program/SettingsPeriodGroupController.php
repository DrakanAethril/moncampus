<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\PeriodGroup;
use App\Entity\Program;
use App\Entity\ProgramPeriodGroup;
use App\Repository\PeriodGroupRepository;
use App\Repository\ProgramPeriodGroupRepository;
use App\Repository\ProgramRepository;
use App\Service\JsonRequestPayload;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Paramétrage, onglet « Groupes de périodes » : rattache à la formation des groupes définis globalement (App\Controller\Settings\PeriodGroupController) et en fixe l'ordre.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsPeriodGroupController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/period-groups', name: 'app_program_settings_period_groups')]
    public function periodGroupsTab(int $id, ProgramRepository $repository, ProgramPeriodGroupRepository $programPeriodGroupRepository, PeriodGroupRepository $periodGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $links = $programPeriodGroupRepository->findAllForProgramOrderedByPriority($program);
        $attachedGroupIds = array_map(static fn (ProgramPeriodGroup $link): ?int => $link->getPeriodGroup()->getId(), $links);

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'period_groups',
            'links' => $links,
            'availablePeriodGroups' => array_values(array_filter(
                $periodGroupRepository->findAllActiveOrderedByName(),
                static fn (PeriodGroup $periodGroup): bool => !\in_array($periodGroup->getId(), $attachedGroupIds, true),
            )),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/period-groups/attach', name: 'app_program_settings_period_groups_attach', methods: ['POST'])]
    public function attachPeriodGroup(int $id, Request $request, ProgramRepository $repository, PeriodGroupRepository $periodGroupRepository, ProgramPeriodGroupRepository $programPeriodGroupRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (!$this->isCsrfTokenValid('program_settings_period_groups_attach', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Pressing "Ajouter" without opening the list is a slip, not a malformed request: the select
        // starts on a disabled empty option, so it posts periodGroupId="". It used to answer a 400
        // (InputBag::getInt() throws on the empty string), and a 404 would be just as unhelpful.
        $periodGroupId = PostValue::nullableInt($request, 'periodGroupId');
        if (null === $periodGroupId) {
            $this->addFlash('danger', 'periodGroupNotChosenFlashMessage');

            return $this->redirectToRoute('app_program_settings_period_groups', ['id' => $program->getId()]);
        }

        $periodGroup = $periodGroupRepository->find($periodGroupId);

        $attachedGroupIds = array_map(
            static fn (ProgramPeriodGroup $link): ?int => $link->getPeriodGroup()->getId(),
            $program->getProgramPeriodGroups()->toArray(),
        );

        if (null === $periodGroup || null !== $periodGroup->getInactiveDate() || \in_array($periodGroup->getId(), $attachedGroupIds, true)) {
            throw $this->createNotFoundException();
        }

        $entityManager->persist(new ProgramPeriodGroup($program, $periodGroup, $programPeriodGroupRepository->findNextPriorityForProgram($program)));
        $entityManager->flush();

        $this->addFlash('success', 'periodGroupAttachedFlashMessage');

        return $this->redirectToRoute('app_program_settings_period_groups', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/period-groups/{linkId}/detach', name: 'app_program_settings_period_groups_detach', methods: ['POST'])]
    public function detachPeriodGroup(int $id, int $linkId, Request $request, ProgramRepository $repository, ProgramPeriodGroupRepository $programPeriodGroupRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $link = $this->findProgramPeriodGroupOrNotFound($programPeriodGroupRepository, $program, $linkId);

        if (!$this->isCsrfTokenValid('program_settings_period_groups_detach', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($link);
        $entityManager->flush();

        $this->addFlash('success', 'periodGroupDetachedFlashMessage');

        return $this->redirectToRoute('app_program_settings_period_groups', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/period-groups/reorder', name: 'app_program_settings_period_groups_reorder', methods: ['POST'])]
    public function reorderPeriodGroups(int $id, Request $request, ProgramRepository $repository, ProgramPeriodGroupRepository $programPeriodGroupRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertValidToken('program_settings_period_groups_reorder', $request);

        $linksById = [];
        foreach ($programPeriodGroupRepository->findAllForProgramOrderedByPriority($program) as $link) {
            $linksById[$link->getId()] = $link;
        }

        $ids = JsonRequestPayload::fromRequest($request)->ids();

        foreach ($ids as $position => $linkId) {
            ($linksById[$linkId] ?? null)?->setPriority($position + 1);
        }
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    private function findProgramPeriodGroupOrNotFound(ProgramPeriodGroupRepository $repository, Program $program, int $linkId): ProgramPeriodGroup
    {
        $link = $repository->find($linkId) ?? throw $this->createNotFoundException();

        if ($link->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $link;
    }
}
