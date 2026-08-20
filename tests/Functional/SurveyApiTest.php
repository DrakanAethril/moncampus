<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignAnswer;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveySeries;
use App\Entity\SurveyTarget;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Enum\SurveyQuestionType;
use App\Repository\SurveyTargetRepository;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The three routes of /api/surveys (design/validated/surveys.md §10.2 and §11).
 *
 * The cases pinned here are the ones the design names: a valid session with no target row gets a
 * 403, an already-answered campaign gets one too, and a payload carrying a `titre` question is
 * refused rather than quietly ignored - a client sending one is counting it, and its progress bar
 * would never reach its maximum.
 *
 * Authentication uses a real LexikJWT token, because /api is a stateless firewall: a session
 * login simply does not apply there, and a test that "logged in" without one would be proving
 * something about a door that is not the one the app uses.
 */
class SurveyApiTest extends FunctionalTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** The Bearer header every /api/* route expects - the same one the mobile app sends. */
    private function authorize(User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /**
     * A launched, open, nominative campaign: one single-choice question, one comment, one
     * intertitle - which must never be answerable.
     *
     * @return array{SurveyCampaign, SurveyCampaignQuestion, SurveyCampaignAnswer, SurveyCampaignQuestion, SurveyCampaignQuestion}
     */
    private function campaign(User $author, ?User $respondent = null, bool $anonymous = false): array
    {
        $entityManager = $this->entityManager();

        $series = new SurveySeries();
        $series->setName('Série API')->setOwner($author);
        $entityManager->persist($series);

        $campaign = new SurveyCampaign();
        $campaign->setSeries($series)->setName('Campagne API')->setCreatedBy($author);
        $campaign->setAudienceTypes([MessageAudienceType::Manual]);
        $campaign->setAnonymous($anonymous);
        $campaign->setTargetFrozenAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($campaign);

        $question = new SurveyCampaignQuestion($campaign);
        $question->setType(SurveyQuestionType::Unique)->setLabel('Le rythme vous convient-il ?')
            ->setOrderIndex(0)->setComparisonKey('key-unique')->setIsScale(true);
        $campaign->addQuestion($question);
        $entityManager->persist($question);

        foreach (['Pas du tout', 'Tout à fait'] as $index => $label) {
            $answer = new SurveyCampaignAnswer($question);
            $answer->setLabel($label)->setOrderIndex($index);
            $question->addAnswer($answer);
            $entityManager->persist($answer);
        }

        $titre = new SurveyCampaignQuestion($campaign);
        $titre->setType(SurveyQuestionType::Titre)->setLabel('Une section')->setOrderIndex(1)->setComparisonKey('key-titre');
        $campaign->addQuestion($titre);
        $entityManager->persist($titre);

        $comment = new SurveyCampaignQuestion($campaign);
        $comment->setType(SurveyQuestionType::Commentaire)->setLabel('Un mot ?')->setOrderIndex(2)
            ->setComparisonKey('key-comment')->setRequired(false);
        $campaign->addQuestion($comment);
        $entityManager->persist($comment);

        if (null !== $respondent) {
            $entityManager->persist(new SurveyTarget($campaign, $respondent));
        }

        $entityManager->flush();

        $first = $question->getAnswers()->first();
        self::assertInstanceOf(SurveyCampaignAnswer::class, $first);

        return [$campaign, $question, $first, $titre, $comment];
    }

    /**
     * The answer, read through the very object the controller reads its input with - which is also
     * the repository's rule: type at the boundary, never cast further in.
     */
    private function json(): JsonRequestPayload
    {
        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    public function testTheListOnlyHoldsWhatThisPersonStillHasToAnswer(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student');
        [$campaign] = $this->campaign($author, $student);

        $this->authorize($student);
        $this->client->request('GET', '/api/surveys');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $surveys = $this->json()->objects('surveys');

        self::assertCount(1, $surveys);
        self::assertSame($campaign->getId(), $surveys[0]->int('id'));
        // The intertitle is out of the count, or the app's progress bar never reaches its maximum.
        self::assertSame(2, $surveys[0]->int('questionCount'));
    }

    /** The case §11 names first: a valid token with no target row gets a 403. */
    public function testATokenWithoutATargetRowIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author2');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student2');
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.outsider');
        [$campaign] = $this->campaign($author, $student);

        $this->authorize($outsider);
        $this->client->request('GET', '/api/surveys/'.$campaign->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('not_targeted', $this->json()->string('error'));
    }

    /** And the second: a campaign already answered is refused too. */
    public function testAnAlreadyAnsweredCampaignIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author3');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student3');
        [$campaign, $question, $answer] = $this->campaign($author, $student);

        $this->authorize($student);
        $this->post($campaign, ['answers' => [['questionId' => $question->getId(), 'answerIds' => [$answer->getId()]]], 'submit' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // The pair: the response *and* survey_target.responded_at.
        $target = static::getContainer()->get(SurveyTargetRepository::class)->findOneFor($campaign, $student);
        self::assertNotNull($target?->getRespondedAt(), 'the API path stamps responded_at just like the web one');

        $this->client->request('GET', '/api/surveys/'.$campaign->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('already_answered', $this->json()->string('error'));
    }

    /** And the third: a payload naming a `titre` question is refused, not ignored. */
    public function testAPayloadCarryingATitreIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author4');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student4');
        [$campaign, , , $titre] = $this->campaign($author, $student);

        $this->authorize($student);
        $this->post($campaign, ['answers' => [['questionId' => $titre->getId(), 'answerIds' => []]]]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /** A draft is accepted and leaves the target untouched - that is what `submit` is for. */
    public function testADraftIsAcceptedAndDoesNotCount(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author5');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student5');
        [$campaign, $question, $answer] = $this->campaign($author, $student);

        $this->authorize($student);
        $this->post($campaign, ['answers' => [['questionId' => $question->getId(), 'answerIds' => [$answer->getId()]]]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->json()->bool('submitted'));

        $target = static::getContainer()->get(SurveyTargetRepository::class)->findOneFor($campaign, $student);
        self::assertNull($target?->getRespondedAt(), 'a draft is not a response');
    }

    /** The payload describes the five types in one shape, with nulls where a type does not apply. */
    public function testTheCampaignPayloadDescribesEveryTypeInOneShape(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author6');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student6');
        [$campaign] = $this->campaign($author, $student);

        $this->authorize($student);
        $this->client->request('GET', '/api/surveys/'.$campaign->getId());

        $body = $this->json();
        $questions = $body->objects('questions');

        self::assertFalse($body->bool('anonymous'));
        self::assertSame(2, $body->int('questionCount'), 'the intertitle is not counted');
        self::assertCount(3, $questions, 'but it is still sent, since it is displayed');

        self::assertSame('unique', $questions[0]->string('type'));
        self::assertTrue($questions[0]->bool('isScale'));
        self::assertNull($questions[0]->int('maxLength'));
        self::assertCount(2, $questions[0]->objects('answers'));

        self::assertSame('titre', $questions[1]->string('type'));
        self::assertSame([], $questions[1]->objects('answers'));

        self::assertSame('commentaire', $questions[2]->string('type'));
        // The cap comes from the server, so the counter the app shows is the real limit.
        self::assertSame(2000, $questions[2]->int('maxLength'));
        self::assertNull($questions[2]->int('minChoices'));
    }

    /** On an anonymous campaign the flag is sent, because the notice is a promise made to the respondent. */
    public function testAnAnonymousCampaignSaysSoInThePayload(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author7');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student7');
        [$campaign] = $this->campaign($author, $student, anonymous: true);

        $this->authorize($student);
        $this->client->request('GET', '/api/surveys/'.$campaign->getId());

        self::assertTrue($this->json()->bool('anonymous'));
    }

    /**
     * For a ranking question the position in the array is the rank - there is no `rank` field the
     * client and the server could contradict. Sending the two answers reversed stores them reversed.
     */
    public function testThePositionInTheArrayIsTheRank(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.author8');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.student8');
        [$campaign, $question] = $this->campaign($author, $student);

        $answers = $question->getAnswers()->toArray();
        $reversed = [(int) $answers[1]->getId(), (int) $answers[0]->getId()];

        $this->authorize($student);
        $this->post($campaign, ['answers' => [['questionId' => $question->getId(), 'answerIds' => $reversed]]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager()->clear();
        $stored = static::getContainer()->get(\App\Repository\SurveyResponseRepository::class)
            ->find((int) $this->json()->int('responseId'));
        self::assertNotNull($stored);

        $ranks = [];
        foreach ($stored->getAnswers()->first()->getSelected() as $selected) {
            $ranks[$selected->getOrderIndex()] = $selected->getCampaignAnswer()?->getId();
        }

        self::assertSame($reversed, [$ranks[0], $ranks[1]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(SurveyCampaign $campaign, array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/surveys/'.$campaign->getId().'/response',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }
}
