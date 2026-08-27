<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\AttendanceStatement;
use App\Entity\AttendanceStatementLine;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AttendanceState;
use App\Enum\Feature;
use App\Enum\GameAttendanceStep;
use App\Repository\AttendanceStatementRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\Game\AttendanceStatementService;
use App\Service\Game\GameAccess;
use App\Service\Game\GamePeriodResolver;
use App\Service\Game\GameSettingsProvider;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The relevé d'assiduité (design's screen 5, under `/programs/{id}/...` rather than the design's
 * `/program/{id}/...`: every other program-scoped route of this application is plural, and one
 * screen spelling it otherwise is a trap for whoever writes the next one) - the cheapest screen of the whole game and the one
 * that feeds its heaviest family.
 *
 * **It is not a cahier d'appel and must never become one.** There is no motive field anywhere on
 * it, no date of absence, no counter: the whole class is net in advance and the teacher clicks the
 * three or four exceptions. That is a minute's work against thirty gestures a session, and it is
 * also why nothing here has anything to keep, to rectify or to protect.
 *
 * The grid saves **at each click** - a `fetch` per card rather than a form - because a form that
 * has to be submitted is a form somebody leaves without submitting, and a half-stated week is worse
 * than no week at all.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameAttendanceController extends AbstractController
{
    #[Route(path: '/programs/{id}/game/attendance', name: 'app_program_game_attendance', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        GameSettingsProvider $settingsProvider,
        AttendanceStatementService $service,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();
        $settings = $settingsProvider->for($program);

        // The unit being stated: today's by default, any other through ?on=YYYY-MM-DD. The arrows
        // of the toolbar walk one unit at a time and never leave the period.
        $day = $this->requestedDay($request, $period->getStartDate(), $period->getEndDate());
        $statement = $service->statementFor($program, $period, $day, $settings->getAttendanceStep(), $this->currentUser());

        [$previous, $next] = $this->neighbours($statement->getStartsOn(), $settings->getAttendanceStep(), $period->getStartDate(), $period->getEndDate());

        return $this->render('game/attendance.html.twig', [
            'program' => $program,
            'period' => $period,
            'settings' => $settings,
            'statement' => $statement,
            'lines' => $this->orderedLines($statement),
            'tally' => $service->tally($statement),
            'states' => AttendanceState::cases(),
            'previousDay' => $previous,
            'nextDay' => $next,
        ]);
    }

    /**
     * One card, answered.
     *
     * A `fetch` from the Stimulus controller, so the answer is the new state and the new tally and
     * nothing else - the grid updates itself rather than reloading thirty cards to change one.
     */
    #[Route(path: '/programs/{id}/game/attendance/{statementId}/state', name: 'app_program_game_attendance_state', requirements: ['id' => '\d+', 'statementId' => '\d+'], methods: ['POST'])]
    public function state(
        int $id,
        int $statementId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        UserRepository $users,
        AttendanceStatementService $service,
        AttendanceStatementRepository $statements,
    ): JsonResponse {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_attendance', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }

        $statement = $statements->find($statementId) ?? throw $this->createNotFoundException();

        if ($statement->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        $payload = JsonRequestPayload::fromRequest($request);
        $student = $users->find($payload->int('student') ?? 0) ?? throw $this->createNotFoundException();
        $state = AttendanceState::tryFrom($payload->string('state')) ?? throw $this->createNotFoundException();

        $stored = $service->setState($statement, $student, $state);

        if (null === $stored) {
            // A statement closed with the screen still open: the click is refused rather than
            // silently dropped, and the grid says so.
            return new JsonResponse(['closed' => true], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'state' => $stored->value,
            'tally' => $service->tally($statement),
        ]);
    }

    /**
     * Both doors, at every entrance: the feature and the formation's own switch (§4, decision 1),
     * then the teacher check. A refusal is a 404 - an extinguished screen does not exist.
     */
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

    private function requestedDay(Request $request, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): \DateTimeImmutable
    {
        $asked = QueryValue::trimmed($request, 'on');
        $day = '' === $asked ? new \DateTimeImmutable('today') : (\DateTimeImmutable::createFromFormat('!Y-m-d', $asked) ?: new \DateTimeImmutable('today'));

        // Clamped rather than refused: a period one is looking at from outside its own dates opens
        // on its nearest edge, which is what somebody stating a term in September actually wants.
        if (null !== $from && $day < $from) {
            return $from;
        }

        if (null !== $to && $day > $to) {
            return $to;
        }

        return $day;
    }

    /**
     * The two arrows of the toolbar, each null at the edge of the period.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function neighbours(\DateTimeImmutable $start, GameAttendanceStep $step, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $back = GameAttendanceStep::Month === $step ? '-1 month' : '-7 days';
        $forward = GameAttendanceStep::Month === $step ? '+1 month' : '+7 days';

        $previous = $start->modify($back);
        $next = $start->modify($forward);

        return [
            null !== $from && $previous < $from ? null : $previous->format('Y-m-d'),
            null !== $to && $next > $to ? null : $next->format('Y-m-d'),
        ];
    }

    /**
     * The grid in the order a teacher reads a class list: by name.
     *
     * @return list<AttendanceStatementLine>
     */
    private function orderedLines(AttendanceStatement $statement): array
    {
        $lines = array_values($statement->getLines()->toArray());
        usort($lines, static fn (AttendanceStatementLine $a, AttendanceStatementLine $b): int => strcasecmp(
            $a->getStudent()->getDisplayName() ?? $a->getStudent()->getUsername(),
            $b->getStudent()->getDisplayName() ?? $b->getStudent()->getUsername(),
        ));

        return $lines;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
