<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\GameEntry;
use App\Entity\GameProgramSettings;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\GameFamily;
use App\Repository\GameEntryRepository;
use App\Repository\GameFigureRepository;
use App\Repository\GameMonthScoreRepository;
use App\Repository\RewardGrantRepository;
use App\Security\Voter\GameGestureVoter;
use App\Service\Game\GameAccess;
use App\Service\Game\GameAliasDrawer;
use App\Service\Game\GameBadgeProvider;
use App\Service\Game\GameCollector;
use App\Service\Game\GameIndexReader;
use App\Service\Game\GameLevelBoard;
use App\Service\Game\GameMonth;
use App\Service\Game\GameMonthCloser;
use App\Service\Game\GameProfileProvider;
use App\Service\Game\GameRankingBuilder;
use App\Service\Game\GameRuleResolver;
use App\Service\Game\GameSettingsProvider;
use App\Service\Game\GameTeamBoard;
use App\Service\Game\GameTrackResolver;
use App\Service\Game\GameYear;
use App\Service\Game\RewardGranter;
use App\Service\Game\TeacherGestureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The student's own side of the campus game.
 *
 * Two doors have to be open for anything here to exist - App\Enum\Feature::Game says whether the
 * establishment runs a game, App\Entity\Program::$gameEnabled says whether this formation plays -
 * and a refusal is a **404** rather than a 403: an extinguished screen does not exist.
 *
 * **Everything is counted by the month it happened in**, and read over a window: the month for the
 * monthly ranking, the school year for the yearly one. Nothing has to be configured before a class
 * can be ranked, and nobody has to know which period they are in.
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
        $settings = $settingsProvider->for($program);

        $month = GameMonth::of(new \DateTimeImmutable());
        [$from, $to] = [$month->firstDay(), $month->lastMoment()];

        // Cheap by construction: everything already written is refused by the ledger, so bringing
        // the month up to date before drawing costs one pass over the sources and never a duplicate.
        $collector->collect($student, $program, $from, $to);

        $standing = $reader->standingFor($student, $program, $from, $to);

        return $this->render('game/index.html.twig', [
            'program' => $program,
            'month' => $month,
            'monthLabel' => $this->monthLabel($month),
            'settings' => $settings,
            'standing' => $standing,
            'badge' => $badges->forUser($student),
            'families' => GameFamily::cases(),
            'journal' => $entries->journal($student, $program, $from, $to, self::JOURNAL_PREVIEW),
            'tier' => $standing->tier($settings->getThresholdBronze(), $settings->getThresholdSilver(), $settings->getThresholdGold()),
            'gestures' => $this->contestableGestures($entries->gesturesFor($student, $program), $teacherGestures),
            'shelf' => $rewards->shelfFor($student),
        ]);
    }

    /** The whole journal of the formation, when the entry screen's dozen lines are not enough. */
    #[Route(path: '/game/journal', name: 'app_game_journal', methods: ['GET'])]
    public function journal(GameAccess $access, GameEntryRepository $entries): Response
    {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();

        return $this->render('game/journal.html.twig', [
            'program' => $program,
            'journal' => $entries->journal($student, $program),
        ]);
    }

    /**
     * « Leveling » - the student's own four-tab reading, reached from the profile menu.
     *
     * - **Mon XP**: the running total across their whole schooling, the level it gives, what the next
     *   one takes, the titles and rewards it opens, and every point ever credited. Not one month:
     *   points are kept for the whole of a schooling and a level is never lost, so a screen showing
     *   one month would be showing the smaller half.
     * - **Ma team**: the same shape for the group, with the collective threshold rather than a rank.
     * - **Ranking**: the month and the year, individually and by team.
     * - **Règles**: how a bonus and the single malus may be given, which is the one thing a student
     *   cannot infer from their own journal.
     */
    #[Route(path: '/game/leveling/{tab}', name: 'app_game_leveling', requirements: ['tab' => 'xp|team|ranking|rules'], defaults: ['tab' => 'xp'], methods: ['GET'])]
    public function leveling(
        string $tab,
        Request $request,
        GameAccess $access,
        GameIndexReader $reader,
        GameEntryRepository $entries,
        GameBadgeProvider $badges,
        GameProfileProvider $profiles,
        GameSettingsProvider $settingsProvider,
        GameLevelBoard $board,
        GameTrackResolver $tracks,
        GameTeamBoard $teamBoard,
        GameRankingBuilder $ranking,
        GameMonthScoreRepository $scores,
        RewardGrantRepository $rewards,
        GameRuleResolver $rules,
    ): Response {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $settings = $settingsProvider->for($program);
        $profile = $profiles->for($student);

        $month = $this->requestedMonth($request);
        $year = GameYear::forProgram($program);

        return $this->render('game/leveling.html.twig', array_merge(
            $this->rankingContext($program, $student, $month, $year, $settings, $ranking, $teamBoard, $scores),
            [
                'tab' => $tab,
                'scope' => 'year' === $request->query->get('scope') ? 'year' : 'month',
                'program' => $program,
                'settings' => $settings,
                'profile' => $profile,
                'badge' => $badges->forUser($student),
                'standing' => $reader->standingFor($student, $program, $month->firstDay(), $month->lastMoment()),
                'families' => GameFamily::cases(),
                // Every point ever credited in this formation, most recent first.
                'history' => $entries->journal($student, $program),
                // The running total across every formation - what the level is made of.
                'totalPoints' => $entries->sumForStudent($student),
                'levels' => $board->boardFor($tracks->forStudent($student, $program)),
                'shelf' => $rewards->shelfFor($student),
                'team' => $teamBoard->forStudent($student, $program, $month->firstDay(), $month->lastMoment()),
                'teamCount' => \count($teamBoard->teams($program, $month->firstDay(), $month->lastMoment())),
                'reachedCount' => $teamBoard->reachedCount($program, $month->firstDay(), $month->lastMoment()),
                'gestureValues' => TeacherGestureService::VALUES,
                'contestDays' => TeacherGestureService::CONTEST_DAYS,
                'podium' => GameMonthCloser::PODIUM,
                'rules' => $rules->all($program),
                'me' => $student,
            ],
        ));
    }

    /**
     * The class ranking: this month, a month gone by, or the whole school year - individually and
     * by team.
     *
     * **One formation, and no other.** There is no « entre promos », no section ranking and no
     * comparison between filières: the frontier of a formation is crossed nowhere.
     */
    #[Route(path: '/game/ranking', name: 'app_game_ranking', methods: ['GET'])]
    public function ranking(
        Request $request,
        GameAccess $access,
        GameRankingBuilder $ranking,
        GameTeamBoard $teamBoard,
        GameProfileProvider $profiles,
        GameSettingsProvider $settingsProvider,
        GameMonthScoreRepository $scores,
    ): Response {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $settings = $settingsProvider->for($program);

        if (!$settings->isRankingEnabled()) {
            throw $this->createNotFoundException();
        }

        $month = $this->requestedMonth($request);
        $year = GameYear::forProgram($program);

        return $this->render('game/ranking.html.twig', array_merge(
            $this->rankingContext($program, $student, $month, $year, $settings, $ranking, $teamBoard, $scores),
            [
                'program' => $program,
                'profile' => $profiles->for($student),
                'podium' => GameMonthCloser::PODIUM,
                'scope' => 'year' === $request->query->get('scope') ? 'year' : 'month',
            ],
        ));
    }

    /** Step out of every ranking, or ask to come back - which the next closure grants. */
    #[Route(path: '/game/ranking/discreet', name: 'app_game_ranking_discreet', methods: ['POST'])]
    public function discreet(Request $request, GameProfileProvider $profiles): Response
    {
        if (!$this->isCsrfTokenValid('game_discreet', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $profile = $profiles->persistent($this->currentUser());
        $wanted = $request->request->getBoolean('discreet');
        $profile->setDiscreet($wanted);
        $profiles->save();

        // Leaving takes effect at once; coming back waits for the next closure. Said in the flash
        // rather than only in the design, because it is the half a student would otherwise discover
        // by refreshing the ranking and finding themselves still absent.
        $this->addFlash('success', $wanted ? 'gameDiscreetOnFlashMessage' : 'gameDiscreetReturnFlashMessage');

        return $this->redirectToRoute('app_game_ranking');
    }

    /** My team, and its threshold. */
    #[Route(path: '/game/team', name: 'app_game_team', methods: ['GET'])]
    public function team(Request $request, GameAccess $access, GameTeamBoard $board, GameSettingsProvider $settingsProvider): Response
    {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $month = $this->requestedMonth($request);
        [$from, $to] = [$month->firstDay(), $month->lastMoment()];

        return $this->render('game/team.html.twig', [
            'program' => $program,
            'month' => $month,
            'team' => $board->forStudent($student, $program, $from, $to),
            'teamCount' => \count($board->teams($program, $from, $to)),
            'reachedCount' => $board->reachedCount($program, $from, $to),
            'settings' => $settingsProvider->for($program),
            'me' => $student,
        ]);
    }

    /** Choosing a figure - three cards, a name, dates and one line on what the person did. */
    #[Route(path: '/game/alias', name: 'app_game_alias', methods: ['GET', 'POST'])]
    public function alias(Request $request, GameAccess $access, GameAliasDrawer $drawer, GameFigureRepository $figures): Response
    {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();

        $alias = $drawer->aliasFor($student, $program);

        if (null === $alias) {
            // No filière on the formation, or an empty catalogue: the game runs without pseudonyms
            // rather than offering a choice that is not one.
            return $this->render('game/alias_none.html.twig', ['program' => $program]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game_alias', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $figure = $figures->find($request->request->getInt('figure'));
            $chosen = null !== $figure && $drawer->choose($alias, $figure);

            $this->addFlash($chosen ? 'success' : 'error', $chosen ? 'gameAliasChosenFlashMessage' : 'gameAliasRefusedFlashMessage');

            return $this->redirectToRoute('app_game_alias');
        }

        return $this->render('game/alias.html.twig', [
            'program' => $program,
            'alias' => $alias,
            'offered' => $figures->findByIds($alias->getOfferedFigures()),
            'deadline' => $alias->deadline(GameAliasDrawer::CHOICE_DAYS),
        ]);
    }

    /** The board of the six levels - open to every role the feature is on for. */
    #[Route(path: '/game/levels', name: 'app_game_levels', methods: ['GET'])]
    public function levels(GameAccess $access, GameLevelBoard $board, GameBadgeProvider $badges, GameTrackResolver $tracks): Response
    {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student);

        return $this->render('game/levels.html.twig', [
            // The board is drawn in the reader's own filière, which in a SIO class is decided by
            // their option and not by the class.
            'entries' => $board->boardFor(null === $program ? null : $tracks->forStudent($student, $program)),
            'badge' => $badges->forUser($student),
        ]);
    }

    /** Spend a consumable - the student does it themselves; the teacher is told, they do not grant it. */
    #[Route(path: '/game/rewards/{grantId}/use', name: 'app_game_reward_use', requirements: ['grantId' => '\d+'], methods: ['POST'])]
    public function useReward(int $grantId, Request $request, RewardGrantRepository $rewards, RewardGranter $granter): Response
    {
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
     * seven days of meaning.
     */
    #[Route(path: '/game/gestures/{entryId}/contest', name: 'app_game_gesture_contest', requirements: ['entryId' => '\d+'], methods: ['POST'])]
    public function contest(int $entryId, Request $request, GameEntryRepository $entries, TeacherGestureService $gestures): Response
    {
        if (!$this->isCsrfTokenValid('game_gesture_contest', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entry = $entries->find($entryId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(GameGestureVoter::CONTEST, $entry);

        // Called once: a second call would find the entry already contested and answer false, which
        // would flash « trop tard » on a contestation that had just been registered.
        $contested = $gestures->contest($entry);

        $this->addFlash(
            $contested ? 'success' : 'error',
            $contested ? 'gameGestureContestedFlashMessage' : 'gameGestureContestTooLateMessage',
        );

        return $this->redirectToRoute('app_game');
    }

    /**
     * Everything the two ranking screens share: the month, the year, both rankings of each, and the
     * months a reader may walk back through.
     *
     * @return array<string, mixed>
     */
    private function rankingContext(
        Program $program,
        User $student,
        GameMonth $month,
        GameYear $year,
        GameProgramSettings $settings,
        GameRankingBuilder $ranking,
        GameTeamBoard $teamBoard,
        GameMonthScoreRepository $scores,
    ): array {
        [$monthFrom, $monthTo] = [$month->firstDay(), $month->lastMoment()];
        [$yearFrom, $yearTo] = [$year->from, $year->to];

        return [
            'month' => $month,
            'monthLabel' => $this->monthLabel($month),
            'year' => $year,
            'ranksThisMonth' => $settings->ranksMonth($month->month),
            'monthRanking' => $ranking->build($program, $monthFrom, $monthTo, $student),
            'monthTeams' => $teamBoard->ranking($program, $monthFrom, $monthTo),
            'yearRanking' => $ranking->build($program, $yearFrom, $yearTo, $student),
            'yearTeams' => $teamBoard->ranking($program, $yearFrom, $yearTo),
            // Only months this formation has actually closed can be walked back to: a month with no
            // frozen ranking has nothing settled to show.
            'closedMonths' => $scores->closedMonths($program),
            'monthClosed' => $scores->isClosed($program, $month->key()),
        ];
    }

    /**
     * « Septembre 2026 » - built here rather than in Twig because twig/intl-extra is not installed,
     * and adding a dependency for one date format is not the trade this screen is worth.
     */
    private function monthLabel(GameMonth $month): string
    {
        $names = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        return ucfirst($names[$month->month] ?? '').' '.$month->year;
    }

    /** The month being read - this one by default, any other through `?month=YYYY-MM`. */
    private function requestedMonth(Request $request): GameMonth
    {
        return GameMonth::fromKey((string) $request->query->get('month', '')) ?? GameMonth::of(new \DateTimeImmutable());
    }

    /**
     * @param list<GameEntry> $entries
     *
     * @return list<array{entry: GameEntry, contestable: bool}>
     */
    private function contestableGestures(array $entries, TeacherGestureService $gestures): array
    {
        return array_map(
            static fn (GameEntry $entry): array => ['entry' => $entry, 'contestable' => $gestures->isContestable($entry)],
            $entries,
        );
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
