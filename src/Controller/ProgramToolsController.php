<?php

namespace App\Controller;

use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GroupCreationMode;
use App\Enum\GroupMixite;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Security\StructureAccessChecker;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\GroupCreationService;
use App\Service\UnsatisfiableGroupConstraintsException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

// Classroom-facing tools reached via the per-program "Outils" nav flyout (between Emploi du temps
// and Syllabus, see templates/layout/app.html.twig) - teacher/staff-only unlike the rest of that
// dropdown, since these are meant to be run live in front of a class, not something a student
// should be able to reach (StructureAccessChecker::isProgramTeacher(), stricter than the plain
// isProgramVisible() every other program-scoped controller here uses).
class ProgramToolsController extends AbstractController
{
    private const string GROUP_CREATION_CSRF_TOKEN_ID = 'program_group_creation';

    #[Route(path: '/programs/{id}/tools/random-draw', name: 'app_program_tools_random_draw')]
    public function randomDraw(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $optionsByStudentId = $studentOptionRepository->findOptionsByStudentForProgram($program);

        $students = array_map(
            static fn (User $student): array => [
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                'optionIds' => array_map(
                    static fn (Option $option): int => $option->getId(),
                    $optionsByStudentId[$student->getId()] ?? [],
                ),
            ],
            $this->sortedByName($program->getStudents()->toArray()),
        );

        return $this->render('program/tools_random_draw.html.twig', [
            'program' => $program,
            'students' => $students,
        ]);
    }

    // See design/design_campus_manager/PROMPT_CLAUDE_CODE_groupes.md for the full spec this
    // implements. The roster/option data embedded here mirrors randomDraw()'s shape (plus each
    // student's id, needed to reference them in absent/pair/lot payloads) - the placement
    // algorithm itself runs server-side (generateGroups()), not in the browser.
    #[Route(path: '/programs/{id}/tools/group-creation', name: 'app_program_tools_group_creation')]
    public function groupCreation(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository, GroupBatchRepository $groupBatchRepository): Response
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $roster = $this->buildRoster($program, $studentOptionRepository);

        $lots = array_map(
            fn (GroupBatch $lot): array => [
                'id' => $lot->getId(),
                'name' => $lot->getName(),
                // Hydrated against the CURRENT roster - a student who's since left the Program is
                // simply dropped from the reloaded lot rather than erroring.
                'groups' => array_map(
                    static fn (array $ids): array => array_values(array_filter(array_map(static fn (int $sid): ?array => $roster[$sid] ?? null, $ids))),
                    $lot->getGroups(),
                ),
            ],
            $groupBatchRepository->findAllForTeacherAndProgram($this->currentUser(), $program),
        );

        return $this->render('program/tools_group_creation.html.twig', [
            'program' => $program,
            'students' => array_values($roster),
            'lots' => $lots,
        ]);
    }

    #[Route(path: '/programs/{id}/tools/group-creation/generate', name: 'app_program_tools_group_creation_generate', methods: ['POST'])]
    public function generateGroups(
        int $id,
        Request $request,
        ProgramRepository $repository,
        StructureAccessChecker $accessChecker,
        ProgramStudentOptionRepository $studentOptionRepository,
        GroupCreationService $groupCreationService,
    ): JsonResponse {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $payload = json_decode($request->getContent(), true) ?? [];

        $mode = GroupCreationMode::tryFrom((string) ($payload['mode'] ?? ''));
        $mixite = GroupMixite::tryFrom((string) ($payload['mixite'] ?? ''));
        if (null === $mode || null === $mixite) {
            return $this->json(['error' => 'Paramètres invalides.'], 422);
        }

        $value = max(2, (int) ($payload['value'] ?? 2));
        $optionFilter = $payload['option'] ?? 'all';
        $absentIds = array_map('intval', \is_array($payload['absentIds'] ?? null) ? $payload['absentIds'] : []);
        $separatePairs = $this->normalizePairs($payload['separatePairs'] ?? []);
        $togetherPairs = $this->normalizePairs($payload['togetherPairs'] ?? []);
        $rebrasser = true === ($payload['rebrasser'] ?? false);
        $lockedIndices = array_map('intval', \is_array($payload['lockedIndices'] ?? null) ? $payload['lockedIndices'] : []);

        $roster = $this->buildRoster($program, $studentOptionRepository);
        $scope = 'all' === $optionFilter
            ? $roster
            : array_filter($roster, static fn (array $s): bool => \in_array((int) $optionFilter, $s['optionIds'], true));
        $availablePool = array_values(array_filter($scope, static fn (array $s): bool => !\in_array($s['id'], $absentIds, true)));
        $totalScopedCount = \count($availablePool);

        if ($rebrasser && \is_array($payload['existingGroups'] ?? null)) {
            $existingGroups = array_map(
                static fn (array $ids): array => array_values(array_filter(array_map(static fn ($sid): ?array => $roster[(int) $sid] ?? null, $ids))),
                $payload['existingGroups'],
            );
            $lockedIds = [];
            foreach ($lockedIndices as $index) {
                foreach ($existingGroups[$index] ?? [] as $student) {
                    $lockedIds[] = $student['id'];
                }
            }
            $remainingPool = array_values(array_filter($availablePool, static fn (array $s): bool => !\in_array($s['id'], $lockedIds, true)));
        } else {
            $groupCount = GroupCreationMode::Count === $mode ? $value : max(1, (int) ceil(\count($availablePool) / $value));
            $existingGroups = array_fill(0, $groupCount, []);
            $lockedIndices = [];
            $remainingPool = $availablePool;
        }

        try {
            $groups = $groupCreationService->createGroups(
                array_map(static fn (array $group): array => array_map(static fn (array $s): array => ['id' => $s['id'], 'optionId' => $s['optionId']], $group), $existingGroups),
                $lockedIndices,
                array_map(static fn (array $s): array => ['id' => $s['id'], 'optionId' => $s['optionId']], $remainingPool),
                $totalScopedCount,
                $mode,
                $value,
                $mixite,
                $separatePairs,
                $togetherPairs,
            );
        } catch (UnsatisfiableGroupConstraintsException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json([
            'groups' => array_map(
                static fn (array $group): array => array_map(static fn (array $s): array => $roster[$s['id']], $group),
                $groups,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/tools/group-creation/lots', name: 'app_program_tools_group_creation_save_lot', methods: ['POST'])]
    public function saveLot(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $payload = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($payload['name'] ?? ''));
        if ('' === $name) {
            $name = 'Lot du '.(new \DateTimeImmutable())->format('d/m/Y');
        }

        $groups = array_values(array_map(
            static fn ($group): array => array_values(array_map('intval', \is_array($group) ? $group : [])),
            \is_array($payload['groups'] ?? null) ? $payload['groups'] : [],
        ));
        if ([] === $groups) {
            return $this->json(['error' => 'Aucun groupe à enregistrer.'], 422);
        }

        $teacher = $this->currentUser();

        // Re-saving under a name that already exists overwrites that lot, matching the design's
        // own "same name = replace" expectation - never two lots with the same name.
        $existing = null;
        foreach ($groupBatchRepository->findAllForTeacherAndProgram($teacher, $program) as $lot) {
            if ($lot->getName() === $name) {
                $existing = $lot;

                break;
            }
        }

        if (null !== $existing) {
            $existing->setGroups($groups);
            $batch = $existing;
        } else {
            $batch = new GroupBatch($program, $teacher, $name, $groups);
            $entityManager->persist($batch);
        }

        $entityManager->flush();

        return $this->json(['id' => $batch->getId(), 'name' => $batch->getName()]);
    }

    #[Route(path: '/programs/{id}/tools/group-creation/lots/{lotId}/delete', name: 'app_program_tools_group_creation_delete_lot', methods: ['POST'])]
    public function deleteLot(int $id, int $lotId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $lot = $groupBatchRepository->findOneForTeacherAndProgram($lotId, $this->currentUser(), $program) ?? throw $this->createNotFoundException();
        $entityManager->remove($lot);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // A real (non-fetch) form POST, not AJAX - lets the browser handle the file download itself
    // via Content-Disposition, same pattern as EcoParcoursController::pdf(). $request->request's
    // "groups"/"lotName" fields are built client-side from whatever's currently on screen (see
    // group_creation_controller.js), not re-derived from the database - a PDF of an unsaved,
    // manually-adjusted result must reflect exactly what the teacher is looking at.
    #[Route(path: '/programs/{id}/tools/group-creation/pdf', name: 'app_program_tools_group_creation_pdf', methods: ['POST'])]
    public function exportGroupsPdf(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GotenbergClient $gotenbergClient): Response
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->request->get('_token'));

        $groups = json_decode((string) $request->request->get('groups', '[]'), true);
        $lotName = trim((string) $request->request->get('lotName', ''));
        if (!\is_array($groups) || [] === $groups) {
            throw $this->createNotFoundException();
        }

        $html = $this->renderView('program/tools_group_creation_pdf.html.twig', [
            'program' => $program,
            'lotName' => $lotName,
            'groups' => $groups,
            'date' => new \DateTimeImmutable(),
        ]);

        try {
            $pdf = $gotenbergClient->convertHtmlToPdf($html);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'programToolsGroupCreationPdfFailedFlashMessage');

            return $this->redirectToRoute('app_program_tools_group_creation', ['id' => $program->getId()]);
        }

        $filename = (new AsciiSlugger())->slug($program->getShortName().'-groupes')->lower()->toString();

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s.pdf', $filename)),
        ]);
    }

    // Also a real form POST, not AJAX - stages the composition in the session for
    // MessageController::compose() to pick up as initial form data, then redirects there so the
    // teacher reviews/edits before actually sending (never auto-sent) - see that action's
    // docblock.
    #[Route(path: '/programs/{id}/tools/group-creation/send-message', name: 'app_program_tools_group_creation_send_message', methods: ['POST'])]
    public function sendGroupsToMessaging(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->request->get('_token'));

        $groups = json_decode((string) $request->request->get('groups', '[]'), true);
        $lotName = trim((string) $request->request->get('lotName', ''));
        if (!\is_array($groups) || [] === $groups) {
            throw $this->createNotFoundException();
        }

        $subject = '' !== $lotName
            ? \sprintf('Groupes — %s — %s', $program->getShortName(), $lotName)
            : \sprintf('Groupes — %s', $program->getShortName());

        $bodyParts = [];
        foreach ($groups as $group) {
            $title = htmlspecialchars((string) ($group['title'] ?? ''), \ENT_QUOTES);
            $memberNames = array_map(
                static fn ($member): string => htmlspecialchars((string) ($member['name'] ?? ''), \ENT_QUOTES),
                \is_array($group['members'] ?? null) ? $group['members'] : [],
            );
            $bodyParts[] = \sprintf('<p><strong>%s</strong><br>%s</p>', $title, implode('<br>', $memberNames));
        }

        $request->getSession()->set('pending_message_draft', ['subject' => $subject, 'body' => implode('', $bodyParts)]);

        return $this->redirectToRoute('app_messages_new');
    }

    private function findForTeacherOrStaff(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array<int, array{id: int, name: string, optionId: ?int, optionIds: list<int>}> */
    private function buildRoster(Program $program, ProgramStudentOptionRepository $studentOptionRepository): array
    {
        $optionsByStudentId = $studentOptionRepository->findOptionsByStudentForProgram($program);
        $roster = [];

        foreach ($this->sortedByName($program->getStudents()->toArray()) as $student) {
            $optionIds = array_map(static fn (Option $option): int => $option->getId(), $optionsByStudentId[$student->getId()] ?? []);
            $roster[$student->getId()] = [
                'id' => $student->getId(),
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                'optionId' => $optionIds[0] ?? null,
                'optionIds' => $optionIds,
            ];
        }

        return $roster;
    }

    /** @return list<array{0: int, 1: int}> */
    private function normalizePairs(mixed $rawPairs): array
    {
        $pairs = [];
        foreach (\is_array($rawPairs) ? $rawPairs : [] as $pair) {
            if (\is_array($pair) && 2 === \count($pair)) {
                $pairs[] = [(int) $pair[0], (int) $pair[1]];
            }
        }

        return $pairs;
    }

    private function assertCsrf(?string $token): void
    {
        if (!$this->isCsrfTokenValid(self::GROUP_CREATION_CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function sortedByName(array $users): array
    {
        usort($users, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $users;
    }
}
