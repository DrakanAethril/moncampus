<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\GameStatement;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AttendanceState;
use App\Enum\CouncilMention;
use App\Enum\Feature;
use App\Enum\GameAttendanceStep;
use App\Enum\GameStatementType;
use App\Repository\GameStatementRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\Game\GameAccess;
use App\Service\Game\GameSettingsProvider;
use App\Service\Game\GameStatementService;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Relevés: the list, the creation, and the two grids (design's screens 5 and 6).
 *
 * **A relevé belongs to no evaluation period at all.** Until 2026-08-27 there was one attendance
 * pass per week *of a period* and exactly one council *per period*, so a team could hold neither
 * four councils in a two-period year nor one across both. Now a relevé is created by hand, named by
 * hand, and there may be as many or as few as the team wants. Its points are filed into the **month**
 * its own date falls in, which is a window every calendar already has and nobody has to configure.
 *
 * The two grids save **at each click or keystroke** rather than on a submit: a form that has to be
 * submitted is a form somebody leaves without submitting, and a half-stated council is worse than
 * no council.
 *
 * The council is the professeur principal's screen (`isProgramReferentTeacher()`, deliberately not
 * staff-bypassed) plus an administrator's; the attendance pass is any teacher of the class's.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameStatementController extends AbstractController
{
    #[Route(path: '/programs/{id}/game/statements', name: 'app_program_game_statements', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementRepository $statements,
        GameSettingsProvider $settingsProvider,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $all = $statements->findForProgram($program);

        return $this->render('game/statements.html.twig', [
            'program' => $program,
            'statements' => $all,
            'types' => GameStatementType::cases(),
            'steps' => GameAttendanceStep::cases(),
            'settings' => $settingsProvider->for($program),
            'canRunCouncil' => $this->canRunCouncil($program, $accessChecker),
        ]);
    }

    #[Route(path: '/programs/{id}/game/statements/new', name: 'app_program_game_statements_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementService $service,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_statement_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = GameStatementType::tryFrom((string) $request->request->get('type'));
        $name = trim((string) $request->request->get('name'));
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('held_on')) ?: new \DateTimeImmutable('today');

        if (null === $type || '' === $name) {
            $this->addFlash('error', 'gameStatementNameRequiredMessage');

            return $this->redirectToRoute('app_program_game_statements', ['id' => $program->getId()]);
        }

        if (GameStatementType::Council === $type && !$this->canRunCouncil($program, $accessChecker)) {
            throw $this->createAccessDeniedException();
        }

        $statement = GameStatementType::Attendance === $type
            ? $service->createAttendance($program, $name, GameAttendanceStep::tryFrom((string) $request->request->get('periodicity')) ?? GameAttendanceStep::Week, $day, $this->currentUser())
            : $service->createCouncil($program, $name, $day, $this->currentUser());

        return $this->redirectToRoute('app_program_game_statement', ['id' => $program->getId(), 'statementId' => $statement->getId()]);
    }

    #[Route(path: '/programs/{id}/game/statements/{statementId}', name: 'app_program_game_statement', requirements: ['id' => '\d+', 'statementId' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        int $statementId,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementRepository $statements,
        GameStatementService $service,
        ProgramStudentOptionRepository $studentOptions,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $statement = $this->openStatement($statements, $statementId, $program, $accessChecker);

        $service->refreshLines($statement);

        $isCouncil = GameStatementType::Council === $statement->getType();

        return $this->render($isCouncil ? 'game/council.html.twig' : 'game/attendance.html.twig', [
            'program' => $program,
            'statement' => $statement,
            'lines' => $this->orderedLines($statement),
            'tally' => $service->tally($statement),
            'states' => AttendanceState::cases(),
            'mentions' => CouncilMention::cases(),
            // The council lists each student's options: a SIO council has to see who is SLAM and
            // who is SISR without leaving the screen.
            'optionsByStudent' => $isCouncil ? $studentOptions->findOptionsByStudentForProgram($program) : [],
            'canReopen' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    /** One card or one mention, answered on the spot. */
    #[Route(path: '/programs/{id}/game/statements/{statementId}/state', name: 'app_program_game_statement_state', requirements: ['id' => '\d+', 'statementId' => '\d+'], methods: ['POST'])]
    public function state(
        int $id,
        int $statementId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementRepository $statements,
        UserRepository $users,
        GameStatementService $service,
    ): JsonResponse {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $statement = $this->openStatement($statements, $statementId, $program, $accessChecker);

        if (!$this->isCsrfTokenValid('game_statement', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }

        $payload = JsonRequestPayload::fromRequest($request);
        $student = $users->find($payload->int('student') ?? 0) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $author = $this->currentUser();

        if (GameStatementType::Attendance === $statement->getType()) {
            $state = AttendanceState::tryFrom($payload->string('state')) ?? throw $this->createNotFoundException();
            $stored = $service->setState($statement, $student, $state, $author);

            return null === $stored
                ? new JsonResponse(['closed' => true], Response::HTTP_CONFLICT)
                : new JsonResponse(['state' => $stored->value, 'tally' => $service->tally($statement)]);
        }

        $mentionValue = $payload->string('mention');

        if ('' !== $mentionValue) {
            $mention = CouncilMention::tryFrom($mentionValue) ?? throw $this->createNotFoundException();
            $stored = $service->setMention($statement, $student, $mention, $author);

            return null === $stored
                ? new JsonResponse(['closed' => true], Response::HTTP_CONFLICT)
                // The value of the mention just placed, and nothing else about this student: no
                // index, no rank, no total. The council feeds the game; the game never weighs on it.
                : new JsonResponse(['mention' => $stored->value, 'points' => $stored->points(), 'stated' => $statement->statedCount()]);
        }

        return $service->setComment($statement, $student, $payload->string('comment'), $author)
            ? new JsonResponse(['stated' => $statement->statedCount()])
            : new JsonResponse(['closed' => true], Response::HTTP_CONFLICT);
    }

    #[Route(path: '/programs/{id}/game/statements/{statementId}/close', name: 'app_program_game_statement_close', requirements: ['id' => '\d+', 'statementId' => '\d+'], methods: ['POST'])]
    public function close(
        int $id,
        int $statementId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementRepository $statements,
        GameStatementService $service,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $statement = $this->openStatement($statements, $statementId, $program, $accessChecker);

        if (!$this->isCsrfTokenValid('game_statement_close', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $service->close($statement);
        $this->addFlash('success', 'gameStatementClosedFlashMessage');

        return $this->redirectToRoute('app_program_game_statement', ['id' => $program->getId(), 'statementId' => $statement->getId()]);
    }

    #[Route(path: '/programs/{id}/game/statements/{statementId}/reopen', name: 'app_program_game_statement_reopen', requirements: ['id' => '\d+', 'statementId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reopen(
        int $id,
        int $statementId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameStatementRepository $statements,
        GameStatementService $service,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $statement = $this->openStatement($statements, $statementId, $program, $accessChecker);

        if (!$this->isCsrfTokenValid('game_statement_reopen', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $service->reopen($statement, $this->currentUser());
        $this->addFlash('success', 'gameStatementReopenedFlashMessage');

        return $this->redirectToRoute('app_program_game_statement', ['id' => $program->getId(), 'statementId' => $statement->getId()]);
    }

    private function openStatement(GameStatementRepository $statements, int $statementId, Program $program, StructureAccessChecker $accessChecker): GameStatement
    {
        $statement = $statements->find($statementId) ?? throw $this->createNotFoundException();

        if ($statement->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        if (GameStatementType::Council === $statement->getType() && !$this->canRunCouncil($program, $accessChecker)) {
            throw $this->createAccessDeniedException();
        }

        return $statement;
    }

    /** The professeur principal, or an administrator - isProgramReferentTeacher() carries no staff bypass. */
    private function canRunCouncil(Program $program, StructureAccessChecker $accessChecker): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $accessChecker->isProgramReferentTeacher($program);
    }

    /** @return list<\App\Entity\GameStatementLine> */
    private function orderedLines(GameStatement $statement): array
    {
        $lines = array_values($statement->getLines()->toArray());
        usort($lines, static fn ($a, $b): int => strcasecmp(
            $a->getStudent()->getDisplayName() ?? $a->getStudent()->getUsername(),
            $b->getStudent()->getDisplayName() ?? $b->getStudent()->getUsername(),
        ));

        return $lines;
    }

    private function openProgram(int $id, ProgramRepository $programs, GameAccess $access, StructureAccessChecker $accessChecker): Program
    {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
