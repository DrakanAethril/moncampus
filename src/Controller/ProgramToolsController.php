<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\RandomDraw;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\GroupCreationMode;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\RandomDrawRepository;
use App\Security\StructureAccessChecker;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\GroupBatchNaming;
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
use Symfony\Contracts\Translation\TranslatorInterface;

// Classroom-facing tools reached via the per-program "Outils" nav flyout (between Emploi du temps
// and Syllabus, see templates/layout/app.html.twig) - teacher/staff-only unlike the rest of that
// dropdown, since these are meant to be run live in front of a class, not something a student
// should be able to reach (StructureAccessChecker::isProgramTeacher(), stricter than the plain
// isProgramVisible() every other program-scoped controller here uses).
#[RequiresFeature(Feature::ClassTools)]
class ProgramToolsController extends AbstractController
{
    private const string GROUP_CREATION_CSRF_TOKEN_ID = 'program_group_creation';
    private const string RANDOM_DRAW_CSRF_TOKEN_ID = 'program_random_draw';

    #[Route(path: '/programs/{id}/tools/random-draw', name: 'app_program_tools_random_draw')]
    public function randomDraw(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository, RandomDrawRepository $randomDrawRepository): Response
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

        $rosterIds = array_column($students, 'id');

        return $this->render('program/tools_random_draw.html.twig', [
            'program' => $program,
            'students' => $students,
            'draws' => array_map(
                fn (RandomDraw $draw): array => $this->drawPayload($draw, $rosterIds),
                $randomDrawRepository->findAllForTeacherAndProgram($this->currentUser(), $program),
            ),
        ]);
    }

    // « Enregistrer ce tirage » and « Enregistrer comme nouveau tirage » - one endpoint, because
    // both create a row. The same reflex as saveLot(): a name already taken is disambiguated rather
    // than made to overwrite, or the duplication button would eat the very copy it was asked for.
    // An empty field is not an error either - a teacher who just wants the draw kept gets « Tirage
    // du 12/09/2026 » and can rename it later from the banner.
    #[Route(path: '/programs/{id}/tools/random-draw/draws', name: 'app_program_tools_random_draw_save', methods: ['POST'])]
    public function saveDraw(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository, RandomDrawRepository $randomDrawRepository, GroupBatchNaming $naming, TranslatorInterface $translator, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertRandomDrawCsrf($request);

        $teacher = $this->currentUser();
        $payload = JsonRequestPayload::fromRequest($request);

        $name = trim($payload->string('name'));
        if ('' === $name) {
            $name = \sprintf('%s %s', $translator->trans('programToolsDefaultDrawNamePrefix'), (new \DateTimeImmutable())->format('d/m/Y'));
        }

        $draw = new RandomDraw($program, $teacher, $naming->unique($name, array_map(
            static fn (RandomDraw $other): string => $other->getName(),
            $randomDrawRepository->findAllForTeacherAndProgram($teacher, $program),
        )));
        $this->applyDrawState($draw, $payload, $program, $studentOptionRepository);

        $entityManager->persist($draw);
        $entityManager->flush();

        return $this->json($this->drawPayload($draw, $this->rosterIds($program)));
    }

    // The only place a name is REFUSED rather than numbered: renaming is a correction, and silently
    // turning « Oral anglais » into « Oral anglais (2) » because that name is already in the banner
    // would leave the teacher looking at two chips they cannot tell apart, which is the exact
    // outcome the rule exists to prevent. The browser says so and keeps the field open.
    #[Route(path: '/programs/{id}/tools/random-draw/draws/{drawId}/rename', name: 'app_program_tools_random_draw_rename', methods: ['POST'])]
    public function renameDraw(int $id, int $drawId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, RandomDrawRepository $randomDrawRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertRandomDrawCsrf($request);

        $teacher = $this->currentUser();
        $draw = $randomDrawRepository->findOneForTeacherAndProgram($drawId, $teacher, $program) ?? throw $this->createNotFoundException();

        $name = trim(JsonRequestPayload::fromRequest($request)->string('name'));
        if ('' === $name) {
            return $this->json(['error' => 'Nom vide.'], 422);
        }

        foreach ($randomDrawRepository->findAllForTeacherAndProgram($teacher, $program) as $other) {
            if ($other !== $draw && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                return $this->json(['error' => 'duplicate_name'], 409);
            }
        }

        $draw->setName($name)->touch();
        $entityManager->flush();

        return $this->json($this->drawPayload($draw, $this->rosterIds($program)));
    }

    // The autosave. Called on every draw, reset, option change and replacement toggle while a saved
    // draw is open - which is what makes the tool survive the browser tab, the whole point of the
    // feature. Deliberately idempotent and total: the browser sends the state it is looking at, and
    // the row becomes exactly that, rather than a diff nobody could replay.
    #[Route(path: '/programs/{id}/tools/random-draw/draws/{drawId}/update', name: 'app_program_tools_random_draw_update', methods: ['POST'])]
    public function updateDraw(int $id, int $drawId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository, RandomDrawRepository $randomDrawRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertRandomDrawCsrf($request);

        $draw = $randomDrawRepository->findOneForTeacherAndProgram($drawId, $this->currentUser(), $program) ?? throw $this->createNotFoundException();
        $this->applyDrawState($draw, JsonRequestPayload::fromRequest($request), $program, $studentOptionRepository);
        $entityManager->flush();

        return $this->json($this->drawPayload($draw, $this->rosterIds($program)));
    }

    #[Route(path: '/programs/{id}/tools/random-draw/draws/{drawId}/delete', name: 'app_program_tools_random_draw_delete', methods: ['POST'])]
    public function deleteDraw(int $id, int $drawId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, RandomDrawRepository $randomDrawRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertRandomDrawCsrf($request);

        $draw = $randomDrawRepository->findOneForTeacherAndProgram($drawId, $this->currentUser(), $program) ?? throw $this->createNotFoundException();
        $entityManager->remove($draw);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    /**
     * The state the browser is looking at, written onto the row. The Option is re-read from the
     * Program rather than trusted, and the drawn ids are filtered against the roster: an id the
     * class no longer holds is dropped here as well as on the way out, so a student who left
     * between two lessons never comes back through a stale payload.
     */
    private function applyDrawState(RandomDraw $draw, JsonRequestPayload $payload, Program $program, ProgramStudentOptionRepository $studentOptionRepository): void
    {
        $optionId = $payload->int('optionId');
        $option = null;
        if (null !== $optionId) {
            foreach ($program->getOptions() as $candidate) {
                if ($candidate->getId() === $optionId) {
                    $option = $candidate;
                    break;
                }
            }
        }

        $rosterIds = $this->rosterIds($program);
        $drawnIds = array_values(array_unique(array_filter(
            $payload->ids('drawnIds'),
            static fn (int $studentId): bool => \in_array($studentId, $rosterIds, true),
        )));

        $draw->setOption($option)
            ->setAllowRepeat($payload->bool('allowRepeat'))
            ->setDrawnStudentIds($drawnIds)
            ->touch();
    }

    /**
     * @param list<int> $rosterIds
     *
     * @return array{id: ?int, name: string, optionId: ?int, allowRepeat: bool, drawnIds: list<int>}
     */
    private function drawPayload(RandomDraw $draw, array $rosterIds): array
    {
        return [
            'id' => $draw->getId(),
            'name' => $draw->getName(),
            'optionId' => $draw->getOption()?->getId(),
            'allowRepeat' => $draw->isAllowRepeat(),
            // Silently ignored on resume, as the handoff asks: a student the class no longer holds
            // is not an error to report, it is a line that has stopped meaning anything.
            'drawnIds' => array_values(array_filter(
                $draw->getDrawnStudentIds(),
                static fn (int $studentId): bool => \in_array($studentId, $rosterIds, true),
            )),
        ];
    }

    /** @return list<int> */
    private function rosterIds(Program $program): array
    {
        return array_values(array_map(
            static fn (User $student): int => $student->getId(),
            $program->getStudents()->toArray(),
        ));
    }

    private function assertRandomDrawCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::RANDOM_DRAW_CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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
    public function saveLot(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, GroupBatchNaming $naming, EntityManagerInterface $entityManager): JsonResponse
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
        $lots = $groupBatchRepository->findAllForTeacherAndProgram($teacher, $program);

        // This endpoint always *creates*, including under a name that is already taken - which is
        // « Dupliquer ». It used to overwrite the lot carrying that name instead, and that rule had
        // to go the day duplicating became a button of its own: it would silently eat the very copy
        // the teacher just asked for. Overwriting now has its own endpoint (updateLot()), and the
        // names are kept apart by GroupBatchNaming rather than by collapsing two lots into one.
        $batch = new GroupBatch($program, $teacher, $naming->unique($name, array_map(
            static fn (GroupBatch $lot): string => $lot->getName(),
            $lots,
        )), $groups);
        $entityManager->persist($batch);
        $entityManager->flush();

        return $this->json(['id' => $batch->getId(), 'name' => $batch->getName()]);
    }

    // « Mettre à jour » - the loaded lot's own row takes the name and the composition currently on
    // screen, in place. Both travel together on purpose: renaming and re-arranging are one gesture
    // for the teacher (they are looking at one set of groups), and splitting them would mean a
    // screen that is half saved.
    //
    // findOneForTeacherAndProgram() scopes to the OWNER, which is what forbids updating a lot a
    // colleague shared with you - loading that one and saving makes a lot of your own, the way it
    // always did.
    #[Route(path: '/programs/{id}/tools/group-creation/batches/{lotId}/update', name: 'app_program_tools_group_creation_update_lot', methods: ['POST'])]
    public function updateLot(int $id, int $lotId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, GroupBatchRepository $groupBatchRepository, GroupBatchNaming $naming, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $this->assertCsrf($request->headers->get('X-CSRF-Token'));

        $teacher = $this->currentUser();
        $lot = $groupBatchRepository->findOneForTeacherAndProgram($lotId, $teacher, $program) ?? throw $this->createNotFoundException();

        $payload = JsonRequestPayload::fromRequest($request);

        $groups = $payload->intLists('groups');
        if ([] === $groups) {
            return $this->json(['error' => 'Aucun groupe à enregistrer.'], 422);
        }

        // An empty field keeps the name the lot already carries, rather than stamping today's date
        // over it the way saving a brand-new lot does: emptying the box is how you rename nothing.
        $name = trim($payload->string('name'));
        if ('' !== $name) {
            // Its own name is left out of the taken list - otherwise re-saving without renaming
            // would push the lot to « (2) » by itself.
            $lot->setName($naming->unique($name, array_values(array_map(
                static fn (GroupBatch $other): string => $other->getName(),
                array_filter(
                    $groupBatchRepository->findAllForTeacherAndProgram($teacher, $program),
                    static fn (GroupBatch $other): bool => $other !== $lot,
                ),
            ))));
        }

        $lot->setGroups($groups);
        $entityManager->flush();

        return $this->json(['id' => $lot->getId(), 'name' => $lot->getName()]);
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
