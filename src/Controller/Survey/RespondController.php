<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveyResponse;
use App\Entity\User;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyTargetRepository;
use App\Security\Voter\SurveyVoter;
use App\Service\Survey\SurveyResponseRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes sondages » and the screen one answers on - the respondent's side
 * (design/validated/surveys.md §8, lot 3).
 *
 * Open to every account, deliberately: a survey reaches students through their travail à faire, but
 * teachers, staff and tutors have no travail à faire at all, and this screen plus the home card are
 * their only door (§7.9).
 *
 * Nothing here recomputes an audience. Having a survey_target row without responded_at, on an open
 * campaign, *is* the right to answer - the frozen target is the permission.
 */
#[IsGranted('ROLE_USER')]
class RespondController extends AbstractController
{
    // Where the id of an anonymous draft is kept: an anonymous response stores no respondent, so
    // there is nothing in the database to find it back by. Session-scoped, per campaign.
    private const string DRAFT_SESSION_KEY = 'survey_drafts';

    #[Route(path: '/my-surveys', name: 'app_my_surveys')]
    public function index(SurveyTargetRepository $targets): Response
    {
        $user = $this->currentUser();

        return $this->render('survey/my_surveys.html.twig', [
            'pending' => $targets->findPendingForUser($user),
            'answered' => $targets->findAnsweredForUser($user),
        ]);
    }

    #[Route(path: '/my-surveys/{id}', name: 'app_survey_respond', methods: ['GET', 'POST'])]
    public function respond(
        int $id,
        Request $request,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyResponseRecorder $recorder,
    ): Response {
        $campaign = $campaigns->findWithQuestions($id);

        if (null === $campaign) {
            throw $this->createNotFoundException();
        }

        $user = $this->currentUser();
        $target = $targets->findOneFor($campaign, $user);

        // Already answered: the screen says so rather than 403-ing, because « j'ai déjà répondu »
        // is a legitimate thing to come and check.
        if (null !== $target && $target->hasResponded()) {
            return $this->render('survey/answered.html.twig', ['campaign' => $campaign, 'target' => $target]);
        }

        $this->denyAccessUnlessGranted(SurveyVoter::RESPOND, $campaign);

        $draft = $recorder->draftFor($campaign, $user, $this->rememberedDraftId($request, $campaign));
        $this->rememberDraft($request, $campaign, $draft);

        if ($request->isMethod('POST')) {
            return $this->save($request, $campaign, $user, $draft, $recorder);
        }

        return $this->render('survey/respond.html.twig', [
            'campaign' => $campaign,
            'response' => $draft,
            'questions' => $campaign->getQuestions()->toArray(),
            'answerableCount' => $campaign->answerableQuestionCount(),
            'given' => $this->givenOf($draft),
        ]);
    }

    private function save(Request $request, SurveyCampaign $campaign, User $user, SurveyResponse $draft, SurveyResponseRecorder $recorder): Response
    {
        if (!$this->isCsrfTokenValid('survey_respond', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submit = 'submit' === $request->request->get('intent');
        $answers = $this->readAnswers($request, $campaign);

        $recorder->record($campaign, $user, $draft, $answers, false);

        if (!$submit) {
            $this->addFlash('success', 'surveyDraftSavedFlashMessage');

            return $this->redirectToRoute('app_survey_respond', ['id' => $campaign->getId()]);
        }

        $missing = $recorder->missingRequired($campaign, $draft);

        if ([] !== $missing) {
            $this->addFlash('error', 'surveyRequiredQuestionsMissingErrorMessage');

            return $this->render('survey/respond.html.twig', [
                'campaign' => $campaign,
                'response' => $draft,
                'questions' => $campaign->getQuestions()->toArray(),
                'answerableCount' => $campaign->answerableQuestionCount(),
                'given' => $this->givenOf($draft),
                'missing' => array_map(static fn (SurveyCampaignQuestion $q): int => (int) $q->getId(), $missing),
            ]);
        }

        // The pair - submitted_at and survey_target.responded_at - written in one transaction by
        // the recorder, which is what keeps the response rate and the reminder honest.
        $recorder->submit($campaign, $user, $draft);

        $this->addFlash('success', 'surveyResponseRecordedFlashMessage');

        return $this->redirectToRoute('app_my_surveys');
    }

    /**
     * The submitted answers, read off the request into the shape the recorder takes. A question of
     * the snapshot that the payload does not name at all is still written - as a skipped one - so
     * that « vue et passée » stays distinguishable from « jamais atteinte ».
     *
     * @return array<int, array{answerIds?: list<int>, freeText?: string|null}>
     */
    private function readAnswers(Request $request, SurveyCampaign $campaign): array
    {
        $answers = [];

        foreach ($campaign->answerableQuestions() as $question) {
            $questionId = (int) $question->getId();

            if ($question->getType()->hasAnswers()) {
                /** @var array<array-key, mixed> $picked */
                $picked = $request->request->all('question_'.$questionId);
                $ids = [];
                foreach ($picked as $value) {
                    if (is_numeric($value)) {
                        $ids[] = (int) $value;
                    }
                }
                $answers[$questionId] = ['answerIds' => $ids];

                continue;
            }

            /** @var string|null $text */
            $text = $request->request->get('question_'.$questionId);
            $answers[$questionId] = ['freeText' => \is_string($text) ? $text : null];
        }

        return $answers;
    }

    /**
     * What the draft already holds, keyed by question id - so a reload shows the respondent what
     * they had said. Ordre questions keep the rank they were given.
     *
     * @return array<int, array{answerIds: list<int>, freeText: string}>
     */
    private function givenOf(SurveyResponse $response): array
    {
        $given = [];

        foreach ($response->getAnswers() as $answer) {
            $question = $answer->getQuestion();

            if (null === $question) {
                continue;
            }

            $ids = [];
            foreach ($answer->getSelected() as $selected) {
                $ids[] = (int) $selected->getCampaignAnswer()?->getId();
            }

            $given[(int) $question->getId()] = ['answerIds' => $ids, 'freeText' => (string) $answer->getFreeText()];
        }

        return $given;
    }

    private function rememberedDraftId(Request $request, SurveyCampaign $campaign): ?int
    {
        /** @var array<array-key, mixed> $drafts */
        $drafts = $request->getSession()->get(self::DRAFT_SESSION_KEY, []);
        $value = $drafts[(string) $campaign->getId()] ?? null;

        return \is_int($value) ? $value : null;
    }

    private function rememberDraft(Request $request, SurveyCampaign $campaign, SurveyResponse $draft): void
    {
        /** @var array<array-key, mixed> $drafts */
        $drafts = $request->getSession()->get(self::DRAFT_SESSION_KEY, []);
        $drafts[(string) $campaign->getId()] = (int) $draft->getId();
        $request->getSession()->set(self::DRAFT_SESSION_KEY, $drafts);
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
