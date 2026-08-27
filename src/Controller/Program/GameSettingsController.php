<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\GameRule;
use App\Entity\GameTeamSet;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\GameAttendanceStep;
use App\Enum\GameTeamMode;
use App\Enum\GameTrack;
use App\Repository\GameFigureRepository;
use App\Repository\GameRuleRepository;
use App\Repository\GameTeamSetRepository;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramRepository;
use App\Repository\RewardItemRepository;
use App\Security\StructureAccessChecker;
use App\Service\Game\GameAccess;
use App\Service\Game\GameLevelResolver;
use App\Service\Game\GamePeriodResolver;
use App\Service\Game\GameRuleCatalog;
use App\Service\Game\GameRuleResolver;
use App\Service\Game\GameSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The one settings screen a teaching team should ever have to open (design's screen 14).
 *
 * **It sits under the formation, not under the general settings**, and that is what materialises §4,
 * decision 1: a formation tunes its game, switches it on, switches it off - and nothing it does
 * reaches the class next door.
 *
 * The green line at the top is the second switch, and it says without hedging that **the first one
 * can make it moot**: a formation whose game is on while `Feature::Game` is off sees nothing at all.
 * That is the kind of state a screen has to announce rather than leave somebody to work out.
 *
 * The four weights are what the screen leads with because they are **the real barème** (§2): setting
 * 30/30/25/15 is a pedagogical statement readable in one line, where tuning forty rule values is
 * readable nowhere.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameSettingsController extends AbstractController
{
    #[Route(path: '/programs/{id}/settings/game', name: 'app_program_settings_game', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GameSettingsProvider $settingsProvider,
        GamePeriodResolver $periods,
        GameLevelResolver $levels,
        GameRuleResolver $rules,
        GameRuleRepository $ruleRepository,
        GameFigureRepository $figures,
        RewardItemRepository $rewards,
        GroupBatchRepository $batches,
        GameTeamSetRepository $teamSets,
        EntityManagerInterface $entityManager,
    ): Response {
        $program = $this->openProgram($id, $programs, $accessChecker);
        $settings = $settingsProvider->for($program);
        $period = $periods->activePeriod($program);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game_settings', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $this->save($request, $program, $settingsProvider, $periods, $ruleRepository, $batches, $teamSets, $entityManager);
            $this->addFlash('success', 'gameSettingsSavedFlashMessage');

            return $this->redirectToRoute('app_program_settings_game', ['id' => $program->getId()]);
        }

        $periodCount = $periods->periodCount($program);

        return $this->render('game/settings.html.twig', [
            'program' => $program,
            'settings' => $settings,
            'period' => $period,
            'featureOpen' => $access->isFeatureOpenForAnyone(),
            'enabledPrograms' => $this->countEnabled($programs),
            'totalPrograms' => \count($programs->findAllActiveWithStudents()),
            'periodCount' => $periodCount,
            'coefficient' => $levels->coefficient($periodCount),
            // The numeric fields are built here rather than derived in the template: Twig has no
            // camel-casing filter, and a screen that guessed getter names from field names would
            // break silently the first time one of them was renamed.
            'weights' => [
                'weight_attendance' => ['label' => 'gameFamilyAttendanceLabel', 'value' => $settings->getWeightAttendance()],
                'weight_work' => ['label' => 'gameFamilyWorkLabel', 'value' => $settings->getWeightWork()],
                'weight_engagement' => ['label' => 'gameFamilyEngagementLabel', 'value' => $settings->getWeightEngagement()],
                'weight_recognition' => ['label' => 'gameFamilyRecognitionLabel', 'value' => $settings->getWeightRecognition()],
            ],
            'numbers' => [
                'threshold_bronze' => ['label' => 'gameSettingsBronzeLabel', 'value' => $settings->getThresholdBronze()],
                'threshold_silver' => ['label' => 'gameSettingsSilverLabel', 'value' => $settings->getThresholdSilver()],
                'threshold_gold' => ['label' => 'gameSettingsGoldLabel', 'value' => $settings->getThresholdGold()],
                'team_threshold' => ['label' => 'gameSettingsTeamThresholdLabel', 'value' => $settings->getTeamThreshold()],
                'gesture_envelope' => ['label' => 'gameSettingsEnvelopeLabel', 'value' => $settings->getGestureEnvelope()],
                'gesture_net_bound' => ['label' => 'gameSettingsNetBoundLabel', 'value' => $settings->getGestureNetBound()],
                'engagement_cap' => ['label' => 'gameSettingsEngagementCapLabel', 'value' => $settings->getEngagementCap()],
                'recognition_cap' => ['label' => 'gameSettingsRecognitionCapLabel', 'value' => $settings->getRecognitionCap()],
                'attendance_streak_cap' => ['label' => 'gameSettingsStreakCapLabel', 'value' => $settings->getAttendanceStreakCap()],
            ],
            'steps' => GameAttendanceStep::cases(),
            'teamModes' => GameTeamMode::cases(),
            'tracks' => GameTrack::cases(),
            'tunable' => GameRuleCatalog::tunable(),
            'ruleValues' => null === $period ? [] : $rules->all($program, $period),
            'figureTally' => $this->figureTally($figures),
            'rewardCount' => \count($rewards->catalogueFor($program)),
            'batches' => $batches->findBy(['program' => $program], ['createdAt' => 'DESC']),
            'teamSet' => null === $period ? null : $teamSets->findForPeriod($program, $period),
        ]);
    }

    private function save(
        Request $request,
        Program $program,
        GameSettingsProvider $settingsProvider,
        GamePeriodResolver $periods,
        GameRuleRepository $ruleRepository,
        GroupBatchRepository $batches,
        GameTeamSetRepository $teamSets,
        EntityManagerInterface $entityManager,
    ): void {
        $settings = $settingsProvider->persistent($program);

        $program->setGameEnabled($request->request->getBoolean('game_enabled'));
        $track = GameTrack::tryFrom((string) $request->request->get('track'));
        $program->setGameTrack($track);

        $settings
            ->setWeightAttendance($this->bounded($request, 'weight_attendance', 0, 100))
            ->setWeightWork($this->bounded($request, 'weight_work', 0, 100))
            ->setWeightEngagement($this->bounded($request, 'weight_engagement', 0, 100))
            ->setWeightRecognition($this->bounded($request, 'weight_recognition', 0, 100))
            ->setEngagementCap($this->bounded($request, 'engagement_cap', 0, 5000))
            ->setRecognitionCap($this->bounded($request, 'recognition_cap', 0, 5000))
            ->setThresholdBronze($this->bounded($request, 'threshold_bronze', 0, 100))
            ->setThresholdSilver($this->bounded($request, 'threshold_silver', 0, 100))
            ->setThresholdGold($this->bounded($request, 'threshold_gold', 0, 100))
            ->setGestureEnvelope($this->bounded($request, 'gesture_envelope', 0, 50))
            ->setGestureNetBound($this->bounded($request, 'gesture_net_bound', 0, 500))
            ->setTeamThreshold($this->bounded($request, 'team_threshold', 0, 100))
            ->setAttendanceStreakCap($this->bounded($request, 'attendance_streak_cap', 0, 500))
            ->setAttendanceStep(GameAttendanceStep::tryFrom((string) $request->request->get('attendance_step')) ?? GameAttendanceStep::Week)
            ->setTeamMode(GameTeamMode::tryFrom((string) $request->request->get('team_mode')) ?? GameTeamMode::Period)
            ->setRankingEnabled($request->request->getBoolean('ranking_enabled'))
            ->setAliasEnabled($request->request->getBoolean('alias_enabled'))
            ->setMalusEnabled($request->request->getBoolean('malus_enabled'))
        ;

        $period = $periods->activePeriod($program);

        if (null !== $period) {
            // The barème is versioned by period: what is saved applies to the period **in progress**,
            // and the periods already closed keep the rules they were played under (§9).
            $this->saveRules($request, $program, $period, $ruleRepository, $entityManager);
            $this->saveTeams($request, $program, $period, $batches, $teamSets, $entityManager);
        }

        $entityManager->flush();
    }

    private function saveRules(Request $request, Program $program, \App\Entity\EvaluationPeriod $period, GameRuleRepository $ruleRepository, EntityManagerInterface $entityManager): void
    {
        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('rules');
        $stored = $ruleRepository->findForPeriod($program, $period);

        foreach (GameRuleCatalog::tunable() as $definition) {
            if (!\array_key_exists($definition->code, $submitted)) {
                continue;
            }

            $raw = $submitted[$definition->code];

            if (!is_numeric($raw)) {
                continue;
            }

            $points = (int) $raw;
            $row = $stored[$definition->code] ?? null;

            if ($points === $definition->points) {
                // Back to the catalogue value: the deviation is deleted rather than stored, so a
                // formation that has never retuned anything really has no rows at all.
                if (null !== $row) {
                    $entityManager->remove($row);
                }

                continue;
            }

            if (null === $row) {
                $entityManager->persist(new GameRule($program, $period, $definition->code, $points));

                continue;
            }

            $row->setPoints($points);
        }
    }

    private function saveTeams(Request $request, Program $program, \App\Entity\EvaluationPeriod $period, GroupBatchRepository $batches, GameTeamSetRepository $teamSets, EntityManagerInterface $entityManager): void
    {
        $batchId = $request->request->getInt('team_batch');
        $set = $teamSets->findForPeriod($program, $period);

        if ($batchId <= 0) {
            if (null !== $set) {
                $entityManager->remove($set);
            }

            return;
        }

        $batch = $batches->find($batchId);

        if (null === $batch || $batch->getProgram()->getId() !== $program->getId()) {
            return;
        }

        if (null === $set) {
            $entityManager->persist(new GameTeamSet($program, $period, $batch));

            return;
        }

        $set->setBatch($batch);
    }

    /**
     * @return array<string, array{total: int, reviewed: int}>
     */
    private function figureTally(GameFigureRepository $figures): array
    {
        $tally = [];
        foreach (GameTrack::cases() as $track) {
            $tally[$track->value] = $figures->tally($track);
        }

        return $tally;
    }

    private function countEnabled(ProgramRepository $programs): int
    {
        return \count(array_filter($programs->findAllActiveWithStudents(), static fn (Program $program): bool => $program->isGameEnabled()));
    }

    private function bounded(Request $request, string $key, int $min, int $max): int
    {
        return max($min, min($max, $request->request->getInt($key)));
    }

    /**
     * The referent teacher of the formation, or an administrator.
     *
     * Deliberately **not** the whole teaching team: the barème is a decision about the class, and
     * isProgramReferentTeacher() answers the factual question without a staff bypass of its own -
     * which is why the administrator branch is written out.
     *
     * The feature/formation conjunction is *not* checked here: this is the screen that switches the
     * formation on, and a screen one can only reach once it is already on could never be used.
     */
    private function openProgram(int $id, ProgramRepository $programs, StructureAccessChecker $accessChecker): Program
    {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramReferentTeacher($program) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
