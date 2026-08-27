<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\GameFamily;
use App\Repository\GameEntryRepository;
use App\Service\Game\GameAccess;
use App\Service\Game\GameBadgeProvider;
use App\Service\Game\GameCollector;
use App\Service\Game\GameIndexReader;
use App\Service\Game\GameLevelBoard;
use App\Service\Game\GamePeriodResolver;
use App\Service\Game\GameSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
            'settings' => $settings,
            'standing' => $standing,
            'badge' => $badges->forUser($student),
            'families' => GameFamily::cases(),
            'journal' => $entries->journal($student, $program, $period, self::JOURNAL_PREVIEW),
            'tier' => $standing->tier($settings->getThresholdBronze(), $settings->getThresholdSilver(), $settings->getThresholdGold()),
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

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
