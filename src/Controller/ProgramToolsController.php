<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GroupCreationMode;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Security\StructureAccessChecker;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\GroupCreationRequest;
use App\Service\GroupCreationService;
use App\Service\JsonRequestPayload;
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
                // The id identifies who has already been drawn: two classmates can shorten to the
                // same "Célia L.", so the name cannot carry that job.
                'id' => $student->getId(),
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                // Both spellings travel to the browser, which switches between them on the toggle -
                // the screen must not go back to the server just to shorten a name.
                'shortName' => $student->getShortDisplayName() ?? $student->getUsername(),
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

        $teacher = $this->currentUser();

        $lots = array_map(
            fn (GroupBatch $lot): array => $this->lotPayload($lot, $roster) + [
                'sharedWith' => array_values(array_map(
                    static fn (User $recipient): int => $recipient->getId(),
                    $lot->getSharedTeachers()->toArray(),
                )),
            ],
            $groupBatchRepository->findAllForTeacherAndProgram($teacher, $program),
        );

        // The second banner, "Groupes partagés avec moi" - read-only copies of a colleague's lot,
        // which is why they carry their owner's name and not the sharing controls.
        $sharedLots = array_map(
            fn (GroupBatch $lot): array => $this->lotPayload($lot, $roster) + [
                'ownerName' => $lot->getTeacher()->getDisplayName() ?? $lot->getTeacher()->getUsername(),
            ],
            $groupBatchRepository->findAllSharedWithTeacherForProgram($teacher, $program),
        );

        // Recipients are the Program's own teachers minus oneself - never the whole directory, so
        // this needs no search field, just the checkbox list the app uses everywhere else.
        $shareableTeachers = array_values(array_map(
            static fn (User $candidate): array => [
                'id' => $candidate->getId(),
                'name' => $candidate->getDisplayName() ?? $candidate->getUsername(),
            ],
            array_filter(
                $this->sortedByName($program->getTeachers()->toArray()),
                static fn (User $candidate): bool => $candidate !== $teacher,
            ),
        ));

        return $this->render('program/tools_group_creation.html.twig', [
            'program' => $program,
            'students' => array_values($roster),
            'lots' => $lots,
            'sharedLots' => $sharedLots,
            'shareableTeachers' => $shareableTeachers,
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

        $draw = GroupCreationRequest::fromRequest($request);
        if (null === $draw) {
            return $this->json(['error' => 'Paramètres invalides.'], 422);
        }

        $roster = $this->buildRoster($program, $studentOptionRepository);
        $scope = null === $draw->optionId
            ? $roster
            : array_filter($roster, static fn (array $s): bool => \in_array($draw->optionId, $s['optionIds'], true));
        $availablePool = array_values(array_filter($scope, static fn (array $s): bool => !\in_array($s['id'], $draw->absentIds, true)));
        $totalScopedCount = \count($availablePool);
        $lockedIndices = $draw->lockedIndices;

        if ($draw->hasExistingGroups) {
            // Re-resolved against the roster, so an id the tool no longer knows simply drops out.
            $existingGroups = array_map(
                static fn (array $ids): array => array_values(array_filter(array_map(static fn (int $sid): ?array => $roster[$sid] ?? null, $ids))),
                $draw->existingGroups,
            );
            $lockedIds = [];
            foreach ($lockedIndices as $index) {
                foreach ($existingGroups[$index] ?? [] as $student) {
                    $lockedIds[] = $student['id'];
                }
            }
            $remainingPool = array_values(array_filter($availablePool, static fn (array $s): bool => !\in_array($s['id'], $lockedIds, true)));
        } else {
            $groupCount = GroupCreationMode::Count === $draw->mode ? $draw->value : max(1, (int) ceil(\count($availablePool) / $draw->value));
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
                $draw->mode,
                $draw->value,
                $draw->mixite,
                $draw->separatePairs,
                $draw->togetherPairs,
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

    #[Route(path: '/programs/{id}/tools/group-creation/batches', name: 'app_program_tools_group_creation_save_lot', methods: ['POST'])]
    public function saveLot(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $payload = JsonRequestPayload::fromRequest($request);
        $name = trim($payload->string('name'));
        if ('' === $name) {
            $name = (new \DateTimeImmutable())->format('d/m/Y');
        }

        $groups = $payload->intLists('groups');
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

    #[Route(path: '/programs/{id}/tools/group-creation/batches/{lotId}/delete', name: 'app_program_tools_group_creation_delete_lot', methods: ['POST'])]
    public function deleteLot(int $id, int $lotId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $lot = $groupBatchRepository->findOneForTeacherAndProgram($lotId, $this->currentUser(), $program) ?? throw $this->createNotFoundException();
        $entityManager->remove($lot);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Opening a saved lot to colleagues who teach the same class. The whole recipient list travels
    // at once and replaces the previous one, so unticking everybody (a legitimate state) un-shares
    // the lot rather than being mistaken for "nothing to do". findOneForTeacherAndProgram() scopes
    // to the OWNER, which is what forbids re-sharing a lot somebody shared with you.
    #[Route(path: '/programs/{id}/tools/group-creation/batches/{lotId}/share', name: 'app_program_tools_group_creation_share_lot', methods: ['POST'])]
    public function shareLot(int $id, int $lotId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $teacher = $this->currentUser();
        $lot = $groupBatchRepository->findOneForTeacherAndProgram($lotId, $teacher, $program) ?? throw $this->createNotFoundException();

        // Re-checked against the Program's teachers rather than trusted: the checkbox list is a
        // convenience, never the control (same reflex as ContentShareComposer's re-reading of ids).
        $requestedIds = JsonRequestPayload::fromRequest($request)->ids('teacherIds');
        $recipients = array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $candidate): bool => $candidate !== $teacher && \in_array($candidate->getId(), $requestedIds, true),
        );

        foreach ($lot->getSharedTeachers()->toArray() as $current) {
            if (!\in_array($current, $recipients, true)) {
                $lot->removeSharedTeacher($current);
            }
        }
        foreach ($recipients as $recipient) {
            $lot->addSharedTeacher($recipient);
        }

        $entityManager->flush();

        return $this->json([
            'sharedWith' => array_values(array_map(static fn (User $recipient): int => $recipient->getId(), $lot->getSharedTeachers()->toArray())),
        ]);
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

        $filename = (new AsciiSlugger())->slug($program->getDisplayShortName().'-groupes')->lower()->toString();

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s.pdf', $filename)),
        ]);
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

    /**
     * The on-screen shape of a saved lot, shared by both banners.
     *
     * @param array<int, array{id: int, name: string, optionId: ?int, optionIds: list<int>}> $roster
     *
     * @return array{id: ?int, name: string, groups: list<list<array{id: int, name: string, optionId: ?int, optionIds: list<int>}>>}
     */
    private function lotPayload(GroupBatch $lot, array $roster): array
    {
        return [
            'id' => $lot->getId(),
            'name' => $lot->getName(),
            // Hydrated against the CURRENT roster - a student who's since left the Program is
            // simply dropped from the reloaded lot rather than erroring.
            'groups' => array_map(
                static fn (array $ids): array => array_values(array_filter(array_map(static fn (int $sid): ?array => $roster[$sid] ?? null, $ids))),
                $lot->getGroups(),
            ),
        ];
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
                'shortName' => $student->getShortDisplayName() ?? $student->getUsername(),
                'optionId' => $optionIds[0] ?? null,
                'optionIds' => $optionIds,
            ];
        }

        return $roster;
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
