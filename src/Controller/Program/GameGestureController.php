<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\GameGestureObject;
use App\Repository\GameEntryRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\GameGestureVoter;
use App\Service\Game\GameAccess;
use App\Service\Game\GameSettingsProvider;
use App\Service\Game\GestureRefused;
use App\Service\Game\TeacherGestureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The teacher's gestures (design's screen 7) - an envelope of six, a free reason, and the single
 * malus of the whole system.
 *
 * The envelope is shown **permanently, in the title**, tokens included. That is not decoration: it
 * is what turns a gesture from a reflex into a decision, and it is what stops the malus from
 * becoming a second disciplinary register - a malus spends a token exactly like a bonus, so posting
 * one is giving up a bonus.
 *
 * The malus form offers two objects, dress or behaviour, and nothing else. The constraint is in the
 * form and in the schema, not only in the documentation.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameGestureController extends AbstractController
{
    #[Route(path: '/programs/{id}/game/gestures', name: 'app_program_game_gestures', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameSettingsProvider $settingsProvider,
        TeacherGestureService $gestures,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $teacher = $this->currentUser();
        $settings = $settingsProvider->for($program);

        $entries = $gestures->listFor($teacher, $program);

        return $this->render('game/gestures.html.twig', [
            'program' => $program,
            'settings' => $settings,
            'entries' => $entries,
            'cancelled' => $this->cancelledMap($entries, $gestures),
            'values' => TeacherGestureService::VALUES,
            'objects' => GameGestureObject::cases(),
            'students' => $this->orderedStudents($program),
        ]);
    }

    #[Route(path: '/programs/{id}/game/gestures/new', name: 'app_program_game_gestures_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function post(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        UserRepository $users,
        TeacherGestureService $gestures,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_gesture', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $student = $users->find($request->request->getInt('student')) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $value = $request->request->getInt('value');
        $isMalus = 'malus' === $request->request->get('kind');
        $object = $isMalus ? GameGestureObject::tryFrom((string) $request->request->get('object')) : null;

        try {
            $gestures->post(
                $this->currentUser(),
                $student,
                $program,
                $isMalus ? -abs($value) : abs($value),
                (string) $request->request->get('reason'),
                $object,
            );
            $this->addFlash('success', 'gameGesturePostedFlashMessage');
        } catch (GestureRefused $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }

        return $this->redirectToRoute('app_program_game_gestures', ['id' => $program->getId()]);
    }

    /**
     * Withdraw a gesture: an inverse line, and the token comes back.
     *
     * The Voter is the authority and it does **not** bypass for staff: a gesture is somebody's
     * signed statement, and withdrawing it under their name is not on offer.
     */
    #[Route(path: '/programs/{id}/game/gestures/{entryId}/cancel', name: 'app_program_game_gestures_cancel', requirements: ['id' => '\d+', 'entryId' => '\d+'], methods: ['POST'])]
    public function cancel(
        int $id,
        int $entryId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameEntryRepository $entries,
        TeacherGestureService $gestures,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_gesture_cancel', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entry = $entries->find($entryId) ?? throw $this->createNotFoundException();

        if ($entry->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(GameGestureVoter::CANCEL, $entry);

        $gestures->cancel($entry, $this->currentUser());
        $this->addFlash('success', 'gameGestureCancelledFlashMessage');

        return $this->redirectToRoute('app_program_game_gestures', ['id' => $program->getId()]);
    }

    /** The author answers a contestation and the gesture stands - withdrawing it is the other route. */
    #[Route(path: '/programs/{id}/game/gestures/{entryId}/respond', name: 'app_program_game_gestures_respond', requirements: ['id' => '\d+', 'entryId' => '\d+'], methods: ['POST'])]
    public function respond(
        int $id,
        int $entryId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameEntryRepository $entries,
        TeacherGestureService $gestures,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_gesture_respond', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entry = $entries->find($entryId) ?? throw $this->createNotFoundException();

        if ($entry->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(GameGestureVoter::RESPOND, $entry);

        $gestures->resolve($entry);
        $this->addFlash('success', 'gameGestureResolvedFlashMessage');

        return $this->redirectToRoute('app_program_game_gestures', ['id' => $program->getId()]);
    }

    /**
     * @param list<GameEntry> $entries
     *
     * @return array<int, bool> entry id => whether an inverse line stands against it
     */
    private function cancelledMap(array $entries, TeacherGestureService $gestures): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $map[(int) $entry->getId()] = $gestures->isCancelled($entry);
        }

        return $map;
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
