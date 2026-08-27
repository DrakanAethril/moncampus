<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CouncilMention;
use App\Enum\Feature;
use App\Repository\ClassCouncilMentionRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\Game\ClassCouncilService;
use App\Service\Game\GameAccess;
use App\Service\Game\GamePeriodResolver;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The class council, entered in one pass (design's screen 6).
 *
 * **No index, no ranking and no game point is displayed anywhere on this screen** - only the value
 * of the mention that has just been placed. The council feeds the game; the game must never weigh
 * on the council, and a screen showing a student's standing while a teacher decides their mention
 * would do exactly that.
 *
 * The whole class sits on one screen, one row per student, one keystroke per mention, saved as it
 * goes: thirty students are entered while the council deliberates, without ever changing page. The
 * points are inserted **at the closure of the council**, in one gesture, and the mentions are locked
 * at that moment; until then everything is corrected in place and nothing is credited.
 *
 * Reserved to the professeur principal - `isProgramReferentTeacher()`, which is deliberately *not*
 * bypassed by staff: holding the class-wide referent remit is a fact, not a permission level. An
 * administrator gets in through the branch below it, because re-opening a closed council is theirs.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class ClassCouncilController extends AbstractController
{
    #[Route(path: '/programs/{id}/council', name: 'app_program_council', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        ClassCouncilMentionRepository $mentions,
        ClassCouncilService $council,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        $rows = $mentions->findForPeriod($program, $period);

        return $this->render('game/council.html.twig', [
            'program' => $program,
            'period' => $period,
            'students' => $this->orderedStudents($program),
            'rows' => $rows,
            'mentions' => CouncilMention::cases(),
            'stated' => \count($rows),
            'closed' => $council->isClosed($program, $period),
            'canReopen' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    /** One mention, or one comment, saved on the keystroke that placed it. */
    #[Route(path: '/programs/{id}/council/state', name: 'app_program_council_state', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function state(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        UserRepository $users,
        ClassCouncilService $council,
    ): JsonResponse {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('game_council', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }

        $payload = JsonRequestPayload::fromRequest($request);
        $student = $users->find($payload->int('student') ?? 0) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $author = $this->currentUser();
        $mentionValue = $payload->string('mention');

        if ('' !== $mentionValue) {
            $mention = CouncilMention::tryFrom($mentionValue) ?? throw $this->createNotFoundException();
            $row = $council->stateMention($program, $period, $student, $mention, $author);
        } else {
            $row = $council->stateComment($program, $period, $student, $payload->string('comment'), $author);
        }

        if (null === $row) {
            return new JsonResponse(['locked' => true], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'mention' => $row->getMention()->value,
            // The value of the mention just placed, and nothing else about this student: no index,
            // no rank, no total - see the class docblock.
            'points' => $row->getMention()->points(),
            'comment' => $row->getComment() ?? '',
        ]);
    }

    /** Close the council: the mentions stop moving and the points are inserted, once. */
    #[Route(path: '/programs/{id}/council/close', name: 'app_program_council_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        ClassCouncilService $council,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('game_council_close', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $council->close($program, $period);
        $this->addFlash('success', 'gameCouncilClosedFlashMessage');

        return $this->redirectToRoute('app_program_council', ['id' => $program->getId()]);
    }

    /** Re-opening a closed council is an administrator's act, and the points follow by inverse lines. */
    #[Route(path: '/programs/{id}/council/reopen', name: 'app_program_council_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reopen(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        GamePeriodResolver $periods,
        ClassCouncilService $council,
    ): Response {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('game_council_reopen', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $council->reopen($program, $period, $this->currentUser());
        $this->addFlash('success', 'gameCouncilReopenedFlashMessage');

        return $this->redirectToRoute('app_program_council', ['id' => $program->getId()]);
    }

    /**
     * The professeur principal, or an administrator.
     *
     * isProgramReferentTeacher() is factual and carries no staff bypass of its own, which is why the
     * administrator branch is written out here rather than assumed.
     */
    private function openProgram(int $id, ProgramRepository $programs, GameAccess $access, StructureAccessChecker $accessChecker): Program
    {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        if (!$accessChecker->isProgramReferentTeacher($program) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    /** @return list<User> */
    private function orderedStudents(Program $program): array
    {
        $students = array_values($program->getStudents()->toArray());
        usort($students, static fn (User $a, User $b): int => strcasecmp(
            $a->getDisplayName() ?? $a->getUsername(),
            $b->getDisplayName() ?? $b->getUsername(),
        ));

        return $students;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
