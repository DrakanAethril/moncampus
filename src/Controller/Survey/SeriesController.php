<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveySeries;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveySeriesRepository;
use App\Repository\SurveyTargetRepository;
use App\Security\Voter\SurveyVoter;
use App\Service\QueryValue;
use App\Service\Survey\SurveyComparison;
use App\Service\Survey\SurveyReplayer;
use App\Service\Survey\SurveyWaveAlignment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comparing the waves of a series, and replaying a survey (design/validated/surveys.md §8, lot 5).
 *
 * « Rejouer ce sondage » does not relaunch a survey: it adds a wave to a series, copying the
 * previous snapshot and audience definition word for word. That is what makes two waves comparable
 * by construction rather than by guesswork.
 *
 * The individual comparison is reachable from here and **never from the menu**: it is the only
 * screen of the feature that looks at a person rather than at a population, so it is arrived at
 * from the overall comparison, deliberately.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SeriesController extends AbstractController
{
    use SurveyTabTrait;

    #[Route(path: '/surveys/series/{id}', name: 'app_survey_series')]
    public function compare(
        int $id,
        SurveySeriesRepository $seriesRepository,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyComparison $comparison,
    ): Response {
        $series = $this->seriesOrNotFound($seriesRepository, $id);
        $waves = $campaigns->findWavesWithQuestions($series);
        $waves = array_values(array_filter($waves, static fn (SurveyCampaign $wave): bool => $wave->isLaunched()));

        $rates = [];
        foreach ($waves as $wave) {
            $rates[$wave->getWaveNumber()] = $targets->responseRate($wave);
        }

        return $this->render('survey/series.html.twig', [
            'series' => $series,
            'waves' => $waves,
            'rates' => $rates,
            'comparison' => $comparison->compare($waves),
            'maxStacked' => SurveyWaveAlignment::MAX_STACKED_WAVES,
        ]);
    }

    /**
     * The individual comparison - « ce que chaque étudiant répondait en septembre, ce qu'il répond
     * en juin ». Refuses out loud when either wave is anonymous, administrators included.
     */
    #[Route(path: '/surveys/series/{id}/individual', name: 'app_survey_series_individual')]
    public function individual(
        int $id,
        Request $request,
        SurveySeriesRepository $seriesRepository,
        SurveyCampaignRepository $campaigns,
        SurveyComparison $comparison,
    ): Response {
        $series = $this->seriesOrNotFound($seriesRepository, $id);
        $waves = array_values(array_filter(
            $campaigns->findWavesWithQuestions($series),
            static fn (SurveyCampaign $wave): bool => $wave->isLaunched(),
        ));

        if (\count($waves) < 2) {
            return $this->render('survey/series_individual.html.twig', [
                'series' => $series,
                'waves' => $waves,
                'refusal' => \App\Service\Survey\SurveyComparisonRefusal::NotEnoughWaves,
                'rows' => [],
                'question' => null,
                'questions' => [],
                'first' => null,
                'second' => null,
            ]);
        }

        // The first and the last wave by default: what one comes here to read is the movement over
        // the whole series, not between two adjacent waves.
        $first = $waves[0];
        $second = $waves[\count($waves) - 1];

        $key = QueryValue::trimmed($request, 'question');
        $result = $comparison->individual($series, $first, $second, '' !== $key ? $key : null);

        return $this->render('survey/series_individual.html.twig', [
            'series' => $series,
            'waves' => $waves,
            'refusal' => $result['refusal'],
            'rows' => $result['rows'],
            'question' => $result['question'],
            // Only the types carrying proposed answers: a verbatim cannot be lined up person by
            // person any more than it can wave by wave.
            'questions' => array_values(array_filter(
                $first->answerableQuestions(),
                static fn (\App\Entity\SurveyCampaignQuestion $question): bool => $question->getType()->hasAnswers(),
            )),
            'first' => $first,
            'second' => $second,
        ]);
    }

    #[Route(path: '/surveys/campaigns/{id}/replay', name: 'app_survey_replay', methods: ['GET', 'POST'])]
    public function replay(
        int $id,
        Request $request,
        SurveyCampaignRepository $campaigns,
        SurveyReplayer $replayer,
    ): Response {
        $campaign = $campaigns->findWithQuestions($id);

        if (null === $campaign) {
            throw $this->createNotFoundException();
        }

        // Replaying is launching, so it is the owner's alone - no staff bypass.
        $this->denyAccessUnlessGranted(SurveyVoter::LAUNCH, $campaign);

        $wave = $replayer->prepare($campaign, $this->currentUser());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('survey_replay', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $name = trim((string) $request->request->get('name'));
            if ('' !== $name) {
                $wave->setName($name);
            }

            $closesAt = trim((string) $request->request->get('closesAt'));
            $wave->setClosesAt('' !== $closesAt ? new \DateTimeImmutable($closesAt) : null);

            $replayer->launch($wave, $campaign);

            $this->addFlash('success', 'surveyReplayedFlashMessage');

            return $this->redirectToRoute('app_survey_campaign', ['id' => $wave->getId()]);
        }

        return $this->render('survey/replay.html.twig', [
            'campaign' => $campaign,
            'wave' => $wave,
        ]);
    }

    private function seriesOrNotFound(SurveySeriesRepository $repository, int $id): SurveySeries
    {
        $series = $repository->find($id);

        if (null === $series) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SurveyVoter::VIEW_RESULTS, $series);

        return $series;
    }
}
