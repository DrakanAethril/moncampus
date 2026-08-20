<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignAnswer;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveyResponse;
use App\Entity\SurveyResponseAnswer;
use App\Entity\SurveyResponseSelectedAnswer;
use App\Entity\User;
use App\Repository\SurveyResponseRepository;
use App\Repository\SurveyTargetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records one response - and stamps survey_target.responded_at in the *same* transaction
 * (design/validated/surveys.md §6).
 *
 * That pairing is the guard the whole design rests on. The content of a response and the fact that
 * somebody answered live on two different rows, on purpose: it is what lets an anonymous campaign
 * say « 18 sur 24 » and remind the other 6 without ever being able to say who answered what. If the
 * two ever desynchronise, the response rate and the reminder both lie in silence - so both the web
 * screen and the mobile API go through this one class rather than writing the pair twice.
 *
 * On an anonymous campaign the respondent is simply not stored. Not hidden, not encrypted, not
 * "kept just in case": absent. There is no name to reveal, to anybody, admins included.
 */
class SurveyResponseRecorder
{
    public function __construct(
        private readonly SurveyTargetRepository $targets,
        private readonly SurveyResponseRepository $responses,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The draft this person is filling in, created on first sight. On an anonymous campaign the
     * draft cannot be found by respondent - there is none stored - so it is looked up by the id the
     * caller kept in session.
     */
    public function draftFor(SurveyCampaign $campaign, User $user, ?int $knownDraftId = null): SurveyResponse
    {
        if (null !== $knownDraftId) {
            $draft = $this->responses->find($knownDraftId);
            if (null !== $draft && $draft->getCampaign() === $campaign && !$draft->isSubmitted()) {
                return $draft;
            }
        }

        if (!$campaign->isAnonymous()) {
            $existing = $this->responses->findDraftFor($campaign, $user);
            if (null !== $existing) {
                return $existing;
            }
        }

        $response = new SurveyResponse($campaign, $campaign->isAnonymous() ? null : $user);
        $this->entityManager->persist($response);
        $this->entityManager->flush();

        return $response;
    }

    /**
     * Writes what the respondent said. A partial write is a draft; $submit turns it into the
     * response, which is when responded_at is stamped.
     *
     * $answers is keyed by SurveyCampaignQuestion id. Each entry carries either a list of picked
     * SurveyCampaignAnswer ids - whose *position in the list is the rank*, for an Ordre question -
     * or a free text. A question that was seen and skipped still gets a row, with neither: that is
     * what tells « seen and passed » apart from « never reached », and what makes the per-question
     * response rate honest.
     *
     * @param array<int, array{answerIds?: list<int>, freeText?: string|null}> $answers
     *
     * @throws \LogicException when the campaign is shut, the person is not in the frozen target,
     *                         they already answered, or a Titre was answered
     */
    public function record(SurveyCampaign $campaign, User $user, SurveyResponse $response, array $answers, bool $submit): SurveyResponse
    {
        $target = $this->targets->findOneFor($campaign, $user);

        if (null === $target) {
            throw new \LogicException('This person is not in the frozen target of the campaign.');
        }

        if ($target->hasResponded()) {
            throw new \LogicException('This person has already answered this campaign.');
        }

        if (!$campaign->isOpenNow()) {
            throw new \LogicException('This campaign is not open.');
        }

        $questions = [];
        foreach ($campaign->getQuestions() as $question) {
            $questions[(int) $question->getId()] = $question;
        }

        foreach ($answers as $questionId => $given) {
            $question = $questions[$questionId] ?? null;

            if (null === $question) {
                throw new \LogicException('Unknown question in this campaign.');
            }

            // An intertitle is not a question: no row is ever created for it, and a payload that
            // names one is refused rather than quietly ignored - a client that sends one is
            // counting it too, and its progress bar would never reach its maximum.
            if (!$question->getType()->isAnswerable()) {
                throw new \LogicException('A "titre" line is never answered.');
            }

            $this->writeAnswer($response, $question, $given);
        }

        if ($submit) {
            $now = new \DateTimeImmutable();
            $response->setSubmittedAt($now);
            // The pair. Same transaction, same flush - never two writes that could drift apart.
            $target->setRespondedAt($now);
        }

        $this->entityManager->flush();

        return $response;
    }

    /**
     * @param array{answerIds?: list<int>, freeText?: string|null} $given
     */
    private function writeAnswer(SurveyResponse $response, SurveyCampaignQuestion $question, array $given): void
    {
        $answer = $response->answerFor($question);

        if (null === $answer) {
            $answer = new SurveyResponseAnswer($response, $question);
            $response->addAnswer($answer);
            $this->entityManager->persist($answer);
        }

        $answer->setAnsweredAt(new \DateTimeImmutable());

        if ($question->getType()->hasAnswers()) {
            $this->applySelection($answer, $question, $given['answerIds'] ?? []);
            $answer->setFreeText(null);

            return;
        }

        // Commentaire. Capped at 2 000 characters, and the counter is shown to the respondent: a
        // silent truncation is a bug, so what arrives longer than that is cut here only as a last
        // resort - the screen and the API both refuse it first.
        $text = trim((string) ($given['freeText'] ?? ''));
        $answer->setFreeText('' === $text ? null : mb_substr($text, 0, SurveyResponseAnswer::FREE_TEXT_MAX_LENGTH));
        $answer->clearSelected();
    }

    /**
     * @param list<int> $answerIds
     */
    private function applySelection(SurveyResponseAnswer $answer, SurveyCampaignQuestion $question, array $answerIds): void
    {
        $available = [];
        foreach ($question->getAnswers() as $candidate) {
            $available[(int) $candidate->getId()] = $candidate;
        }

        // Rewritten whole rather than diffed: a draft answered twice must not keep the first pick,
        // and the rank of an Ordre question is the *position in the submitted list*, so a partial
        // update would leave two rows claiming the same rank.
        foreach ($answer->getSelected() as $previous) {
            $this->entityManager->remove($previous);
        }
        $answer->clearSelected();

        $rank = 0;
        foreach ($answerIds as $answerId) {
            $picked = $available[$answerId] ?? null;

            if (!$picked instanceof SurveyCampaignAnswer) {
                continue;
            }

            $selected = new SurveyResponseSelectedAnswer($answer, $picked);
            $selected->setOrderIndex($rank++);
            $answer->addSelected($selected);
            $this->entityManager->persist($selected);
        }
    }

    /**
     * What a submitted response still owes: the required questions left unanswered. A draft may owe
     * anything; a submission may owe nothing.
     *
     * @return list<SurveyCampaignQuestion>
     */
    public function missingRequired(SurveyCampaign $campaign, SurveyResponse $response): array
    {
        $missing = [];

        foreach ($campaign->answerableQuestions() as $question) {
            if (!$question->isRequired()) {
                continue;
            }

            $answer = $response->answerFor($question);

            if (null === $answer || !$answer->isAnswered()) {
                $missing[] = $question;
            }
        }

        return $missing;
    }

    /**
     * Closes the response: submitted_at and survey_target.responded_at, in one flush.
     *
     * Kept apart from record() so the web screen can write a draft, check what the required
     * questions still owe, and only then close - without the closing ever being a second, separate
     * write that could land while the answers did not.
     */
    public function submit(SurveyCampaign $campaign, User $user, SurveyResponse $response): SurveyResponse
    {
        return $this->record($campaign, $user, $response, [], true);
    }
}
