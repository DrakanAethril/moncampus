<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyResponse;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyTargetRepository;
use App\Service\JsonRequestPayload;
use App\Service\Survey\SurveyQuestionPayload;
use App\Service\Survey\SurveyResponseRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes sondages » on the phone - the three routes of design/validated/surveys.md §10.2.
 *
 * Open to every authenticated account rather than gated on ROLE_STUDENT like Api\QuizController: a
 * survey reaches students through their travail à faire, but teachers, staff and tutors have none
 * at all, and this is their door. What narrows access is not the role, it is the frozen target -
 * having a survey_target row without responded_at *is* the right to answer, and nothing here
 * recomputes an audience.
 *
 * Recording goes through the very same App\Service\Survey\SurveyResponseRecorder the web screen
 * uses, so « stamp responded_at in the same transaction as the response » is written once rather
 * than twice. If the two ever drifted apart, the response rate and the reminder would both lie in
 * silence.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Surveys)]
class SurveyController extends AbstractController
{
    public function __construct(private readonly SurveyQuestionPayload $payloadBuilder)
    {
    }

    /**
     * The surveys this person still has to answer - their target rows without responded_at, on an
     * open campaign. That is « Mes sondages », and nothing else goes into it.
     */
    #[Route(path: '/api/surveys', name: 'api_surveys', methods: ['GET'])]
    public function index(SurveyTargetRepository $targets): JsonResponse
    {
        $pending = [];

        foreach ($targets->findPendingForUser($this->currentUser()) as $target) {
            $campaign = $target->getCampaign();

            if (null === $campaign) {
                continue;
            }

            $pending[] = [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
                'anonymous' => $campaign->isAnonymous(),
                'closesAt' => $campaign->getClosesAt()?->format(\DateTimeInterface::ATOM),
                'questionCount' => $campaign->answerableQuestionCount(),
            ];
        }

        return $this->json(['surveys' => $pending]);
    }

    /**
     * One campaign and its frozen questions. Refuses with a 403 when this person has no target row,
     * has already answered, or the campaign is shut - the three cases are one rule: the frozen
     * target is the permission.
     */
    #[Route(path: '/api/surveys/{id}', name: 'api_survey', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, SurveyCampaignRepository $campaigns, SurveyTargetRepository $targets): JsonResponse
    {
        $campaign = $campaigns->findWithQuestions($id);

        if (null === $campaign) {
            return $this->json(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $refusal = $this->refusalFor($campaign, $targets);

        if (null !== $refusal) {
            return $refusal;
        }

        return $this->json($this->payloadBuilder->campaign($campaign));
    }

    /**
     * Records a response - a draft by default, the response itself when `submit` is true.
     *
     * One route and a flag rather than two routes, because a draft that survives the app being
     * closed is the whole reason drafts exist: a twelve-question survey answered on the bus must
     * not be lost.
     *
     * For a ranking question the **position in the answerIds array is the rank** - there is
     * deliberately no separate `rank` field the client and the server could contradict.
     */
    #[Route(path: '/api/surveys/{id}/response', name: 'api_survey_response', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function respond(
        int $id,
        Request $request,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyResponseRecorder $recorder,
    ): JsonResponse {
        $campaign = $campaigns->findWithQuestions($id);

        if (null === $campaign) {
            return $this->json(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $refusal = $this->refusalFor($campaign, $targets);

        if (null !== $refusal) {
            return $refusal;
        }

        // Typed reading, never a hand-read json_decode.
        $payload = JsonRequestPayload::fromRequest($request);
        $submit = $payload->bool('submit');

        $answers = [];
        foreach ($payload->objects('answers') as $entry) {
            $questionId = $entry->int('questionId');

            if (null === $questionId) {
                continue;
            }

            $given = [];
            if ($entry->has('answerIds')) {
                $given['answerIds'] = $entry->ids('answerIds');
            }
            if ($entry->has('freeText')) {
                $given['freeText'] = $entry->string('freeText');
            }

            $answers[$questionId] = $given;
        }

        $user = $this->currentUser();
        $draft = $recorder->draftFor($campaign, $user, $payload->int('responseId'));

        try {
            // The recorder refuses a « titre » outright rather than ignoring it quietly: a client
            // sending one is counting it too, and its progress bar would never reach its maximum.
            $recorder->record($campaign, $user, $draft, $answers, false);

            if ($submit) {
                $missing = $recorder->missingRequired($campaign, $draft);

                if ([] !== $missing) {
                    return $this->json([
                        'error' => 'missing_required',
                        'questionIds' => array_map(static fn ($question): int => (int) $question->getId(), $missing),
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // The pair - submitted_at and survey_target.responded_at - in one transaction.
                $recorder->submit($campaign, $user, $draft);
            }
        } catch (\LogicException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->stateOf($draft, $submit));
    }

    /**
     * The one rule behind the three refusals: no target row, already answered, or campaign shut.
     */
    private function refusalFor(SurveyCampaign $campaign, SurveyTargetRepository $targets): ?JsonResponse
    {
        $target = $targets->findOneFor($campaign, $this->currentUser());

        if (null === $target) {
            return $this->json(['error' => 'not_targeted'], Response::HTTP_FORBIDDEN);
        }

        if ($target->hasResponded()) {
            return $this->json(['error' => 'already_answered'], Response::HTTP_FORBIDDEN);
        }

        if (!$campaign->isOpenNow()) {
            return $this->json(['error' => 'closed'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateOf(SurveyResponse $response, bool $submitted): array
    {
        return [
            'responseId' => $response->getId(),
            'submitted' => $submitted && $response->isSubmitted(),
        ];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }
}
