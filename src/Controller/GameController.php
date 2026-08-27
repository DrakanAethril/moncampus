<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\GameFamily;
use App\Repository\GameEntryRepository;
use App\Repository\RewardGrantRepository;
use App\Security\Voter\GameGestureVoter;
use App\Service\Game\GameAccess;
use App\Service\Game\GameBadgeProvider;
use App\Service\Game\GameCollector;
use App\Service\Game\GameIndexReader;
use App\Service\Game\GameLevelBoard;
use App\Service\Game\GamePeriodResolver;
use App\Service\Game\GameSettingsProvider;
use App\Service\Game\RewardGranter;
use App\Service\Game\TeacherGestureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Ma progression » - the student's own door into the campus game (screens 1 and 2).
 *
 * Two doors have to be open for anything here to exist, and the attribute only holds one of them:
 * App\Enum\Feature::Game says whether the establishment runs a game, App\Entity\Program::$gameEnabled
 * says whether this formation plays. The conjunction is checked at every entrance, and a refusal is
 * a **404** rather than a 403 - an extinguished screen does not exist, it is not forbidden.
 *
 * What this screen shows, and why each part is not decoration:
 *
 * - **The number is the index**, out of 100, never a total of points. It is the only figure that
 *   compares to a classmate's, and the whole design of §2 hangs from that.
 * - **Every family prints its denominator** - « 9 semaines relevées », « 9 échéances ». A rate whose
 *   possible is not shown has to be believed rather than checked.
 * - **A family with no data says so** instead of showing 0 %: it left the index and its weight went
 *   to the others, which is a working state and not a failure.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameController extends AbstractController
{
    /** How much of the journal the entry screen shows before handing over to the full listing. */
    private const int JOURNAL_PREVIEW = 12;

    #[Route(path: '/game', name: 'app_game', methods: ['GET'])]
    public function index(
        GameAccess $access,
        GamePeriodResolver $periods,
        GameIndexReader $reader,
        GameCollector $collector,
        GameEntryRepository $entries,
        GameBadgeProvider $badges,
        GameSettingsProvider $settingsProvider,
        TeacherGestureService $teacherGestures,
        RewardGrantRepository $rewards,
    ): Response {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $period = $periods->activePeriod($program);

        $settings = $settingsProvider->for($program);

        if (null === $period) {
            // A formation whose calendar has no period cannot score anything. Said plainly rather
            // than drawn as an index of 0, which would read as a bad period rather than as no period.
            return $this->render('game/no_period.html.twig', [
                'program' => $program,
                'badge' => $badges->forUser($student),
            ]);
        }

        // Cheap by construction: everything already written is refused by the ledger, so bringing
        // the period up to date before drawing costs one pass over the sources and never a
        // duplicate line.
        $collector->collect($student, $program, $period);

        $standing = $reader->standingFor($student, $program, $period);

        return $this->render('game/index.html.twig', [
            'program' => $program,
            'period' => $period,
            // The gestures addressed to this student, with the seven-day window still open on the
            // ones they may answer. Shown next to the journal rather than inside it: a gesture is
            // the only line of the journal that can be argued with.
            'gestures' => $this->contestableGestures($entries->gesturesFor($student, $program, $period), $teacherGestures),
            'settings' => $settings,
            'standing' => $standing,
            'badge' => $badges->forUser($student),
            'families' => GameFamily::cases(),
            'journal' => $entries->journal($student, $program, $period, self::JOURNAL_PREVIEW),
            'tier' => $standing->tier($settings->getThresholdBronze(), $settings->getThresholdSilver(), $settings->getThresholdGold()),
            // The shelf: every period, not only this one. A symbolic reward is acquired for good
            // and does not stop existing when its term ends (§5.6).
            'shelf' => $rewards->shelfFor($student),
        ]);
    }

    /** The whole journal of the period, when the entry screen's dozen lines are not enough. */
    #[Route(path: '/game/journal', name: 'app_game_journal', methods: ['GET'])]
    public function journal(
        GameAccess $access,
        GamePeriodResolver $periods,
        GameEntryRepository $entries,
    ): Response {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        return $this->render('game/journal.html.twig', [
            'program' => $program,
            'period' => $period,
            'journal' => $entries->journal($student, $program, $period),
        ]);
    }

    /**
     * Spend a consumable - the student does it themselves.
     *
     * The teacher is notified, they do not grant it: a joker one can refuse is not a reward, it is a
     * request (§5.5). What it may be spent on is written on the reward itself; what it may never be
     * spent on - a graded assessment - is a rule the joker's own description carries, because the
     * application has no way of knowing which piece of work the student means.
     */
    #[Route(path: '/game/rewards/{grantId}/use', name: 'app_game_reward_use', requirements: ['grantId' => '\d+'], methods: ['POST'])]
    public function useReward(
        int $grantId,
        Request $request,
        RewardGrantRepository $rewards,
        RewardGranter $granter,
    ): Response {
        if (!$this->isCsrfTokenValid('game_reward_use', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $grant = $rewards->find($grantId) ?? throw $this->createNotFoundException();

        if ($grant->getStudent()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        $this->addFlash(
            $granter->spend($grant, trim((string) $request->request->get('used_on'))) ? 'success' : 'error',
            'gameRewardSpentFlashMessage',
        );

        return $this->redirectToRoute('app_game');
    }

    /**
     * Contest a gesture, within the seven days.
     *
     * The student's own act and nobody else's - GameGestureVoter::CONTEST answers only to the person
     * the gesture was addressed to, because a teacher contesting on their behalf would empty the
     * seven days of meaning. The entry stays where it is, marked; its author answers or withdraws it,
     * and withdrawing writes an inverse line rather than deleting anything.
     */
    #[Route(path: '/game/gestures/{entryId}/contest', name: 'app_game_gesture_contest', requirements: ['entryId' => '\d+'], methods: ['POST'])]
    public function contest(
        int $entryId,
        Request $request,
        GameEntryRepository $entries,
        TeacherGestureService $gestures,
    ): Response {
        if (!$this->isCsrfTokenValid('game_gesture_contest', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entry = $entries->find($entryId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(GameGestureVoter::CONTEST, $entry);

        // Called once: the second call would find the entry already contested and answer false,
        // which would flash « trop tard » on a contestation that had just been registered.
        $contested = $gestures->contest($entry);

        $this->addFlash(
            $contested ? 'success' : 'error',
            $contested ? 'gameGestureContestedFlashMessage' : 'gameGestureContestTooLateMessage',
        );

        return $this->redirectToRoute('app_game');
    }

    /**
     * The board of the six levels - open to every role the feature is on for.
     *
     * It reads as a poster rather than as a status screen, and that is deliberate: the wording of a
     * level is a figure a student can recognise themselves in, and seeing the six of them at once is
     * what makes the cursus legible from the first semester.
     */
    #[Route(path: '/game/levels', name: 'app_game_levels', methods: ['GET'])]
    public function levels(GameAccess $access, GameLevelBoard $board, GameBadgeProvider $badges): Response
    {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student);

        return $this->render('game/levels.html.twig', [
            'entries' => $board->boardFor($program?->getGameTrack()),
            'badge' => $badges->forUser($student),
        ]);
    }

    /**
     * @param list<\App\Entity\GameEntry> $entries
     *
     * @return list<array{entry: \App\Entity\GameEntry, contestable: bool}>
     */
    private function contestableGestures(array $entries, TeacherGestureService $gestures): array
    {
        return array_map(
            static fn ($entry): array => ['entry' => $entry, 'contestable' => $gestures->isContestable($entry)],
            $entries,
        );
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
