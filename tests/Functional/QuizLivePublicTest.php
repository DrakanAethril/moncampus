<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LiveSessionStatus;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLiveSessionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Public live contests (design/validated/quiz-live-public.md, the user's decisions of 2026-11-07): a
 * contest of a class or of none, its QR code's address /live/{code}, a guest entering under a
 * pseudonym and a drawn avatar, any account entering as itself and never as a guest, anybody joining
 * a contest under way, the host taking a player out of the waiting room, and the results keeping
 * everybody's answers.
 */
class QuizLivePublicTest extends FunctionalTestCase
{
    private User $teacher;
    private User $student;
    private User $outsider;
    private Program $program;
    private QuizTemplate $quiz;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'public.teacher');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'public.student');
        $this->outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'public.outsider');
        $this->program = $this->createProgram([$this->student], [$this->teacher]);

        $this->quiz = new QuizTemplate($this->teacher);
        $this->quiz->setName('Culture numérique');
        $this->quiz->setCreatedBy($this->teacher);
        foreach (['Quel protocole chiffre les échanges ?' => ['HTTPS' => true, 'HTTP' => false], 'Le RGPD vaut pour les associations.' => ['Vrai' => true, 'Faux' => false]] as $label => $answers) {
            $question = new QuizQuestion($this->quiz);
            $question->setType(QuestionType::Qcm);
            $question->setLabel($label);
            $question->setTimeMode(QuestionTimeMode::Quiz);
            $question->setOrderIndex($this->quiz->getQuestions()->count() + 1);
            $index = 0;
            foreach ($answers as $text => $correct) {
                $answer = new QuizAnswer($question);
                $answer->setLabel($text);
                $answer->setIsCorrect($correct);
                $answer->setOrderIndex($index++);
                $question->addAnswer($answer);
            }
            $this->quiz->addQuestion($question);
        }
        $this->em->persist($this->quiz);
        $this->em->flush();
    }

    public function testAContestOfNoClassIsCreatedPublicInTheChosenTheme(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/quiz-live/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="theme"][value="pop"]:checked', 'Pop is offered by default');

        $this->client->request('POST', '/quiz-live/create', [
            '_token' => $this->csrfToken('quiz_live_create'),
            'templateId' => $this->quiz->getId(),
            'programId' => '',
            'theme' => 'citrouille',
        ]);
        self::assertResponseRedirects();
        $session = $this->latest();
        self::assertNull($session->getProgram());
        self::assertTrue($session->isPublic(), 'nobody else could join a contest of no class');
        self::assertSame('citrouille', $session->getTheme());

        $this->client->request('GET', \sprintf('/quiz-live/%d/projector', $session->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('&quot;qrSvg&quot;:&quot;&lt;svg', (string) $this->client->getResponse()->getContent(), 'the waiting room carries the QR code');

        $this->client->request('GET', '/quiz-live?class=none');
        self::assertSelectorTextContains('table', 'Culture numérique');
        $this->client->request('GET', '/quiz-live/themes');
        self::assertResponseIsSuccessful();
    }

    public function testAGuestEntersPlaysAndIsKeptInTheResults(): void
    {
        $session = $this->service()->createSession([$this->quiz], $this->program, $this->teacher, 20, null, true);
        $code = (string) $session->getRoomCode();

        $this->client->request('GET', '/live/'.$code);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="nickname"]');
        // The twenty are drawn among the ménagerie's hundred, handed to the page whole.
        $catalogue = json_decode((string) $this->client->getCrawler()->filter('form[data-quiz-live-guest-catalogue-value]')->attr('data-quiz-live-guest-catalogue-value'), true);
        self::assertIsArray($catalogue);
        self::assertCount(100, $catalogue);

        // An avatar out of the ménagerie - a drawn one, as before it - is refused.
        $this->guest($code, 'Loutre Cosmique', (string) json_encode(['skin' => 2, 'hair' => 'court']));
        self::assertResponseStatusCodeSame(422);
        $this->guest($code, 'Loutre Cosmique', 'licorne');
        self::assertResponseStatusCodeSame(422);

        $this->guest($code, 'Loutre Cosmique');
        self::assertResponseRedirects('/live/'.$code.'/play');
        $guest = $this->em->getRepository(QuizLiveParticipant::class)->findOneBy(['displayName' => 'Loutre Cosmique']);
        self::assertInstanceOf(QuizLiveParticipant::class, $guest);
        self::assertSame('goupil', $guest->getGuestFixedAvatar());
        self::assertNull($guest->getGuestAvatar());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // A second visitor may not take the same name, whatever its case.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/live/'.$code);
        $this->guest($code, 'LOUTRE cosmique', 'axel');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.ql-error', 'Déjà pris');
        self::assertSelectorExists('form[data-quiz-live-guest-kept-value="axel"]', 'the avatar is kept after a refusal');

        // An account is never a guest: the class's student enters at once, under its own name.
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/live/'.$code);
        self::assertResponseRedirects('/live/'.$code.'/play');
        $student = $this->participantOf($this->student);
        self::assertFalse($student->isGuest());
        self::assertSame('Public.student T.', $student->getDisplayName(), '« Prénom N. » in a public contest');

        // The room plays; the guest answers from its own phone.
        $this->service()->start($this->live($session), $this->teacher);
        $this->service()->advance($this->live($session), $this->teacher, LiveSessionStatus::Countdown);
        $this->service()->advance($this->live($session), $this->teacher, LiveSessionStatus::Reading);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/live/'.$code);
        $this->guest($code, 'Retardataire');
        self::assertResponseRedirects('/live/'.$code.'/play', null, 'a contest under way still takes players');
        $this->client->followRedirect();
        $this->client->request('POST', '/live/'.$code.'/answer', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_live_answer'),
        ], json_encode(['answerIds' => [$this->rightAnswerId($session)]], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/quiz-live/%d/results', $session->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Loutre Cosmique');
        self::assertSelectorTextContains('table', 'Retardataire');
        self::assertSelectorTextContains('table', 'Invité');
        self::assertSelectorExists('.cm-live-face[style*="menagerie/goupil"]', 'a guest wears its ménagerie avatar in the results');
        $this->client->request('GET', \sprintf('/quiz-live/%d/results?show=guests', $session->getId()));
        self::assertSelectorTextNotContains('table', 'Public.student');
    }

    public function testAClassContestTakesItsOwnStudentsOnly(): void
    {
        $session = $this->service()->createSession([$this->quiz], $this->program, $this->teacher, 20);
        $code = (string) $session->getRoomCode();

        $this->client->request('GET', '/live/'.$code);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connecte-toi');
        self::assertSelectorNotExists('input[name="nickname"]');

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/live/'.$code);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/live/'.$code);
        self::assertResponseRedirects('/live/'.$code.'/play');
    }

    public function testAPublicContestTakesAnAccountOfAnyClassAndTheHostMayTakeItOut(): void
    {
        $session = $this->service()->createSession([$this->quiz], $this->program, $this->teacher, 20, null, true);
        $code = (string) $session->getRoomCode();

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/live/'.$code);
        self::assertResponseRedirects('/live/'.$code.'/play');
        $outsider = $this->participantOf($this->outsider);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/quiz-live/%d/projector', $session->getId()));
        $this->client->request('POST', \sprintf('/quiz-live/%d/remove', $session->getId()), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_live_control'),
        ], json_encode(['participantId' => $outsider->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/live/'.$code);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'retiré', 'an account taken out is not let back in');
    }

    public function testTheResultsReadEachQuestionsRateAndEachVoteWithoutAName(): void
    {
        $vote = new QuizQuestion($this->quiz);
        $vote->setType(QuestionType::Vote);
        $vote->setLabel('On refait un concours ?');
        $vote->setTimeMode(QuestionTimeMode::Quiz);
        $vote->setOrderIndex(3);
        foreach (['Oui', 'Non'] as $index => $text) {
            $answer = new QuizAnswer($vote);
            $answer->setLabel($text);
            $answer->setIsCorrect(false);
            $answer->setOrderIndex($index);
            $vote->addAnswer($answer);
        }
        $this->quiz->addQuestion($vote);
        $this->em->flush();

        $service = $this->service();
        $session = $service->createSession([$this->quiz], $this->program, $this->teacher, 20, null, true);
        $service->joinAccount($session, $this->student);
        $service->joinAccount($session, $this->outsider);
        $service->start($session, $this->teacher);

        // 1. The student is right, the outsider says nothing. 2. Both are right. 3. The student votes « Oui », the outsider nothing.
        $this->play($session, [$this->student->getId() => 'right']);
        $this->play($session, [$this->student->getId() => 'right', $this->outsider->getId() => 'right']);
        $this->play($session, [$this->student->getId() => 'first']);
        self::assertSame(LiveSessionStatus::Finished, $this->live($session)->getStatus());

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', \sprintf('/quiz-live/%d/results?show=questions', $session->getId()));
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.cm-live-qrate');
        self::assertCount(2, $rows, 'a vote has no rate');
        self::assertStringContainsString('Quel protocole', $rows->eq(0)->text(), 'the weakest question first');
        self::assertSame('50 %', $rows->eq(0)->filter('.cm-live-qrate__value')->text(), 'a silence is a failure');
        self::assertStringContainsString('1 sans réponse', $rows->eq(0)->text());
        self::assertSame('100 %', $rows->eq(1)->filter('.cm-live-qrate__value')->text());
        self::assertSelectorTextNotContains('.cm-live-qrates', 'Public.student', 'no name in the analysis');

        $crawler = $this->client->request('GET', \sprintf('/quiz-live/%d/results?show=questions&sort=number', $session->getId()));
        self::assertSame('100 %', $crawler->filter('.cm-live-qrate__value')->eq(1)->text());

        $crawler = $this->client->request('GET', \sprintf('/quiz-live/%d/results?show=polls', $session->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-tabs', 'Sondages');
        $columns = $crawler->filter('.cm-live-hist__col');
        self::assertSame(['Oui', 'Non', 'Sans réponse'], $columns->filter('.cm-live-hist__label')->each(static fn ($label): string => $label->text()));
        self::assertSame(['Oui : 1 (50 %)', 'Non : 0 (0 %)', 'Sans réponse : 1 (50 %)'], $columns->each(static fn ($column): string => (string) $column->attr('title')), 'out of the players present, silences included');
    }

    public function testAContestWithoutAVoteHasNoPollsTab(): void
    {
        $session = $this->service()->createSession([$this->quiz], null, $this->teacher, 20);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/quiz-live/%d/results?show=polls', $session->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-tabs', 'Analyse par question');
        self::assertSelectorTextNotContains('.cm-tabs', 'Sondages');
        self::assertSelectorExists('table', 'an unknown tab falls back on everybody');
    }

    public function testSomebodyElsesContestDoesNotExist(): void
    {
        $session = $this->service()->createSession([$this->quiz], null, $this->teacher, 20);

        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'public.colleague'));
        $this->client->request('GET', \sprintf('/quiz-live/%d/results', $session->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Plays the next question: opens it, sends each named player's answer (« right », or « first »
     * for the first proposition), then closes it if the room's silence left it open.
     *
     * @param array<int, 'right'|'first'> $picks by user id
     */
    private function play(QuizLiveSession $session, array $picks): void
    {
        $service = $this->service();
        while (LiveSessionStatus::Question !== $this->live($session)->getStatus()) {
            $service->advance($this->live($session), $this->teacher);
        }
        foreach ($picks as $userId => $pick) {
            $user = $this->em->find(User::class, $userId);
            self::assertInstanceOf(User::class, $user);
            $id = 'right' === $pick ? $this->rightAnswerId($session) : (int) $this->live($session)->getCurrentQuestion()?->getAnswers()->first()?->getId();
            $service->submitAnswer($this->live($session), $user, new LiveResponse([$id]));
        }
        if (LiveSessionStatus::Question === $this->live($session)->getStatus()) {
            $service->advance($this->live($session), $this->teacher);
        }
        $service->advance($this->live($session), $this->teacher, LiveSessionStatus::Results);
    }

    private function guest(string $code, string $nickname, string $avatar = 'goupil'): void
    {
        $this->client->request('POST', '/live/'.$code.'/guest', [
            '_token' => $this->csrfToken('quiz_live_guest'),
            'nickname' => $nickname,
            'avatar' => $avatar,
        ]);
    }

    private function service(): QuizLiveSessionService
    {
        return static::getContainer()->get(QuizLiveSessionService::class);
    }

    private function latest(): QuizLiveSession
    {
        $session = $this->em->getRepository(QuizLiveSession::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(QuizLiveSession::class, $session);

        return $session;
    }

    private function live(QuizLiveSession $session): QuizLiveSession
    {
        $fresh = $this->em->find(QuizLiveSession::class, $session->getId());
        self::assertInstanceOf(QuizLiveSession::class, $fresh);

        return $fresh;
    }

    private function participantOf(User $user): QuizLiveParticipant
    {
        $participant = $this->em->getRepository(QuizLiveParticipant::class)->findOneBy(['student' => $user]);
        self::assertInstanceOf(QuizLiveParticipant::class, $participant);

        return $participant;
    }

    private function rightAnswerId(QuizLiveSession $session): int
    {
        $question = $this->live($session)->getCurrentQuestion();
        self::assertNotNull($question);
        foreach ($question->getAnswers() as $answer) {
            if ($answer->isCorrect()) {
                return (int) $answer->getId();
            }
        }
        self::fail('No right answer.');
    }
}
