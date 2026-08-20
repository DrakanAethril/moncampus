<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Entity\SurveyCampaign;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyResponseRepository;
use App\Repository\SurveyTargetRepository;
use App\Security\Voter\SurveyVoter;
use App\Service\Survey\SurveyCsvExporter;
use App\Service\Survey\SurveyResults;
use App\Service\Survey\SurveyTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The results of one campaign - Global, Détail, Non-répondants, and the CSV
 * (design/validated/surveys.md §8, lot 4).
 *
 * Two rules run through all three tabs, and both are about what is *not* shown:
 *
 *  - On an anonymous campaign, the distributions appear only from three responses on. Under that,
 *    the screen says « 2 réponses sur 24 — les résultats s'afficheront à partir de 3 » and nothing
 *    else: on a target of four people, a two-bar histogram points at somebody. The response rate
 *    itself is always shown - it says nothing about the content.
 *  - The detail of an anonymous campaign is sorted by display_key, never by id nor by submitted_at,
 *    and carries no timestamp. Sorting by time is what de-anonymises: the first row would be the
 *    first person to have answered.
 *
 * Opening any of them tops the target up, and only while the campaign is open (§7.2): somebody
 * enrolled after the launch must be able to answer, and nobody is ever removed.
 */
#[IsGranted('ROLE_USER')]
class ResultsController extends AbstractController
{
    use SurveyTabTrait;

    #[Route(path: '/surveys/campaigns/{id}', name: 'app_survey_campaign')]
    public function global(
        int $id,
        SurveyCampaignRepository $campaigns,
        SurveyResults $results,
        SurveyTargetResolver $targetResolver,
    ): Response {
        $campaign = $this->campaignOrNotFound($campaigns, $id);
        $targetResolver->refreshIfOpen($campaign);

        $questionResults = [];
        foreach ($results->forCampaign($campaign) as $result) {
            $questionResults[$result->questionId] = $result;
        }

        // The verbatims of every comment question, read once here rather than from the template.
        $verbatims = [];
        foreach ($campaign->answerableQuestions() as $question) {
            if (!$question->getType()->hasAnswers()) {
                $verbatims[(int) $question->getId()] = $results->verbatims($campaign, $question);
            }
        }

        return $this->render('survey/results_global.html.twig', [
            'campaign' => $campaign,
            'tabs' => $this->resultTabs($campaign, 'app_survey_campaign'),
            'rate' => $results->responseRate($campaign),
            'results' => $questionResults,
            'verbatims' => $verbatims,
            'disclosable' => $results->isDisclosable($campaign),
            'threshold' => SurveyResults::ANONYMOUS_DISCLOSURE_THRESHOLD,
        ]);
    }

    #[Route(path: '/surveys/campaigns/{id}/responses', name: 'app_survey_campaign_detail')]
    public function detail(
        int $id,
        SurveyCampaignRepository $campaigns,
        SurveyResponseRepository $responses,
        SurveyResults $results,
    ): Response {
        $campaign = $this->campaignOrNotFound($campaigns, $id);

        return $this->render('survey/results_detail.html.twig', [
            'campaign' => $campaign,
            'tabs' => $this->resultTabs($campaign, 'app_survey_campaign_detail'),
            'questions' => $campaign->answerableQuestions(),
            // Already sorted by display_key on an anonymous campaign - see the repository.
            'responses' => $responses->findSubmitted($campaign),
            'disclosable' => $results->isDisclosable($campaign),
            'threshold' => SurveyResults::ANONYMOUS_DISCLOSURE_THRESHOLD,
            'rate' => $results->responseRate($campaign),
        ]);
    }

    /**
     * Who has not answered - by name, **even on an anonymous campaign**: knowing *that* somebody
     * has not answered says nothing about what their answers would have been.
     */
    #[Route(path: '/surveys/campaigns/{id}/pending', name: 'app_survey_campaign_pending')]
    public function pending(
        int $id,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyResults $results,
        SurveyTargetResolver $targetResolver,
    ): Response {
        $campaign = $this->campaignOrNotFound($campaigns, $id);
        $targetResolver->refreshIfOpen($campaign);

        return $this->render('survey/results_pending.html.twig', [
            'campaign' => $campaign,
            'tabs' => $this->resultTabs($campaign, 'app_survey_campaign_pending'),
            'pending' => $targets->findPending($campaign),
            'rate' => $results->responseRate($campaign),
        ]);
    }

    /**
     * The reminder. It only marks who was reminded and when - sending the mail is not this lot's
     * business, and the mark is what keeps a second reminder from going out the same day.
     */
    #[Route(path: '/surveys/campaigns/{id}/remind', name: 'app_survey_campaign_remind', methods: ['POST'])]
    public function remind(
        int $id,
        Request $request,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        EntityManagerInterface $entityManager,
    ): Response {
        $campaign = $this->campaignOrNotFound($campaigns, $id);

        if (!$this->isCsrfTokenValid('survey_remind', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $now = new \DateTimeImmutable();
        $reminded = 0;
        foreach ($targets->findPending($campaign) as $target) {
            $target->setRemindedAt($now);
            ++$reminded;
        }
        $entityManager->flush();

        $this->addFlash('success', 'surveyRemindedFlashMessage');
        unset($reminded);

        return $this->redirectToRoute('app_survey_campaign_pending', ['id' => $campaign->getId()]);
    }

    #[Route(path: '/surveys/campaigns/{id}/export', name: 'app_survey_campaign_export')]
    public function export(int $id, SurveyCampaignRepository $campaigns, SurveyCsvExporter $exporter): Response
    {
        $campaign = $this->campaignOrNotFound($campaigns, $id);

        $response = new Response($exporter->export($campaign));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', $exporter->filename($campaign)),
        );

        return $response;
    }

    /**
     * Closing a campaign by hand. Its target stops moving from here on: what was measured was
     * measured.
     */
    #[Route(path: '/surveys/campaigns/{id}/close', name: 'app_survey_campaign_close', methods: ['POST'])]
    public function close(int $id, Request $request, SurveyCampaignRepository $campaigns, EntityManagerInterface $entityManager): Response
    {
        $campaign = $this->campaignOrNotFound($campaigns, $id);

        if (!$this->isCsrfTokenValid('survey_close', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (null === $campaign->getClosedAt()) {
            $campaign->setClosedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        $this->addFlash('success', 'surveyClosedFlashMessage');

        return $this->redirectToRoute('app_survey_campaign', ['id' => $campaign->getId()]);
    }

    /**
     * @return list<array{label: string, url: string, active: bool, count: int|null}>
     */
    private function resultTabs(SurveyCampaign $campaign, string $currentRoute): array
    {
        $id = (int) $campaign->getId();

        return [
            [
                'label' => 'surveyResultsGlobalTabLabel',
                'url' => $this->generateUrl('app_survey_campaign', ['id' => $id]),
                'active' => 'app_survey_campaign' === $currentRoute,
                'count' => null,
            ],
            [
                // « Détail des réponses » on an anonymous campaign, « des répondants » otherwise -
                // the wording changes because on one of the two there is no respondent to name.
                'label' => $campaign->isAnonymous() ? 'surveyResultsAnswersTabLabel' : 'surveyResultsRespondentsTabLabel',
                'url' => $this->generateUrl('app_survey_campaign_detail', ['id' => $id]),
                'active' => 'app_survey_campaign_detail' === $currentRoute,
                'count' => null,
            ],
            [
                'label' => 'surveyResultsPendingTabLabel',
                'url' => $this->generateUrl('app_survey_campaign_pending', ['id' => $id]),
                'active' => 'app_survey_campaign_pending' === $currentRoute,
                'count' => null,
            ],
        ];
    }

    private function campaignOrNotFound(SurveyCampaignRepository $campaigns, int $id): SurveyCampaign
    {
        $campaign = $campaigns->findWithQuestions($id);

        if (null === $campaign) {
            throw $this->createNotFoundException();
        }

        // The owner, plus staff. Anonymity is not a permission this could lift: on an anonymous
        // campaign there is simply no name stored to show, to anybody.
        $this->denyAccessUnlessGranted(SurveyVoter::VIEW_RESULTS, $campaign);

        return $campaign;
    }
}
