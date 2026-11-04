<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Enum\QuizTournamentStandingBlock;
use App\Repository\QuizTournamentRepository;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The tournament screens of lot 2 (design/validated/quiz-tournament.md §3, §6.2, §10): the
 * administrators create, edit and open a tournament in Paramètres › Pédagogique › Tournois, and its
 * readers see the standings page. Who reaches what is QuizTournamentVoter's; these tests pin it per
 * role, and the save-time rules of §3.
 */
class QuizTournamentScreensTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $student;
    private User $otherStudent;
    private User $teacher;
    private User $outsider;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'qt.admin');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'qt.alice');
        $this->otherStudent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'qt.bruno');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'qt.teacher');
        $this->outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'qt.chloe');
        $this->program = $this->createProgram([$this->student, $this->otherStudent], [$this->teacher], $this->admin);
        $this->createProgram([$this->outsider], [], $this->admin);
    }

    public function testAnAdministratorCreatesATournamentThatIsBornClosed(): void
    {
        $quiz = $this->quiz($this->admin, 'Repérer une donnée personnelle', qcm: 20, trueFalse: 5, ignored: 3);
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/settings/tournaments/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['quiz_tournament[name]'] = 'Tournoi RGPD';
        $form['quiz_tournament[startsAt]'] = '2026-11-10T08:00';
        $form['quiz_tournament[endsAt]'] = '2027-01-31T18:00';
        $this->tickProgram($form, $this->program);
        $form['quiz_tournament[sources][0]'] = (string) $quiz->getId();
        $form['quiz_tournament[palette]'] = 'mint';
        $this->client->submit($form);

        self::assertResponseRedirects('/settings/tournaments');
        $tournament = $this->em->getRepository(QuizTournament::class)->findOneBy(['name' => 'Tournoi RGPD']);
        self::assertInstanceOf(QuizTournament::class, $tournament);
        self::assertFalse($tournament->isOpened(), 'a tournament is born closed');
        self::assertSame(QuizTournamentPalette::Mint, $tournament->getPalette());
        self::assertSame([$this->program->getId()], $tournament->getTargetPrograms()->map(static fn (Program $p): ?int => $p->getId())->getValues());
        self::assertSame([$quiz->getId()], $tournament->getSources()->map(static fn (QuizTemplate $q): ?int => $q->getId())->getValues());
        self::assertSame(20, $tournament->getQuestionsPerDuel());
        self::assertSame($this->admin->getId(), $tournament->getCreatedBy()->getId());
    }

    public function testAReserveTooSmallForAFullDuelIsRefusedWithWhatIsMissing(): void
    {
        $quiz = $this->quiz($this->admin, 'Petit quiz', qcm: 10, trueFalse: 8, ignored: 4);
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/settings/tournaments/new');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['quiz_tournament[name]'] = 'Tournoi trop court';
        $form['quiz_tournament[startsAt]'] = '2026-11-10T08:00';
        $form['quiz_tournament[endsAt]'] = '2027-01-31T18:00';
        $this->tickProgram($form, $this->program);
        $form['quiz_tournament[sources][0]'] = (string) $quiz->getId();
        $form['quiz_tournament[palette]'] = 'lavender';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('il manque 2 questions jouables en duel (18 sur 20)', $content);
        self::assertStringContainsString('il manque 5 questions qui ne sont pas des vrai/faux', $content);
        self::assertStringContainsString('4 questions ignorées : apparier.', $content);
        self::assertNull($this->em->getRepository(QuizTournament::class)->findOneBy(['name' => 'Tournoi trop court']));
    }

    public function testATargetingIsRequiredAndTheEndComesAfterTheStart(): void
    {
        $quiz = $this->quiz($this->admin, 'Quiz', qcm: 25, trueFalse: 0, ignored: 0);
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/settings/tournaments/new');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['quiz_tournament[name]'] = 'Tournoi sans cible';
        $form['quiz_tournament[startsAt]'] = '2027-01-31T18:00';
        $form['quiz_tournament[endsAt]'] = '2026-11-10T08:00';
        $form['quiz_tournament[sources][0]'] = (string) $quiz->getId();
        $form['quiz_tournament[palette]'] = 'peach';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Choisissez au moins une section, filière ou formation.', $content);
    }

    public function testOpeningShowsTheTournamentToTheStudentsItReaches(): void
    {
        $tournament = $this->tournament(opened: false);
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/tournaments/'.$tournament->getId());
        self::assertResponseStatusCodeSame(404, 'closed: the administrators alone see it');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/tournaments');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-pf-rows', 'Tournoi RGPD');
        $this->client->request('POST', '/settings/tournaments/'.$tournament->getId().'/open', [
            '_token' => $this->csrfToken('quiz_tournament_open'.$tournament->getId()),
        ]);
        self::assertResponseRedirects('/settings/tournaments');
        $tournament = $this->reload($tournament);
        self::assertTrue($tournament->isOpened());
        self::assertSame($this->admin->getId(), $tournament->getOpenedBy()?->getId());

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/tournaments/'.$tournament->getId());
        self::assertResponseIsSuccessful();
    }

    public function testAfterTheFirstDuelOnlyTheNameTheEndAndTheTargetingMove(): void
    {
        $tournament = $this->tournament(opened: true);
        $this->duel($tournament, $this->student, $this->otherStudent, new \DateTimeImmutable('-2 days'), 1020, 980);
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/settings/tournaments/'.$tournament->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cm-pf-banner--warn');
        self::assertCount(0, $crawler->filter('[name="quiz_tournament[palette]"]'), 'the palette is no longer a field');
        self::assertCount(0, $crawler->filter('[name="quiz_tournament[startsAt]"]'));

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['quiz_tournament[name]'] = 'Tournoi RGPD 2027';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/tournaments');
        $tournament = $this->reload($tournament);
        self::assertSame('Tournoi RGPD 2027', $tournament->getName());
        self::assertSame(QuizTournamentPalette::Lavender, $tournament->getPalette());

        // And nothing is deleted once a duel exists.
        $this->client->request('POST', '/settings/tournaments/'.$tournament->getId().'/delete', [
            '_token' => $this->csrfToken('quiz_tournament_delete'.$tournament->getId()),
        ]);
        self::assertResponseRedirects('/settings/tournaments');
        self::assertNotNull($this->em->getRepository(QuizTournament::class)->find((int) $tournament->getId()));
    }

    public function testATournamentWithoutADuelMayBeDeleted(): void
    {
        $tournament = $this->tournament(opened: false);
        $id = (int) $tournament->getId();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/tournaments');
        $this->client->request('POST', '/settings/tournaments/'.$id.'/delete', [
            '_token' => $this->csrfToken('quiz_tournament_delete'.$id),
        ]);
        self::assertResponseRedirects('/settings/tournaments');
        $this->em->clear();
        self::assertNull(static::getContainer()->get(QuizTournamentRepository::class)->find($id));
    }

    public function testWhoReadsATournamentAndItsAdministration(): void
    {
        $tournament = $this->tournament(opened: true);
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'qt.staff');
        $tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'qt.tutor');
        $page = '/tournaments/'.$tournament->getId();

        $this->assertScreens($this->student, [$page => 200, '/tournaments' => 200, '/settings/tournaments' => 403, '/settings/tournaments/new' => 403]);
        $this->assertScreens($this->outsider, [$page => 404, '/tournaments' => 200]);
        $this->assertScreens($this->teacher, [$page => 200, '/settings/tournaments' => 403]);
        $this->assertScreens($staff, [$page => 200, '/settings/tournaments' => 404, '/settings/tournaments/new' => 404]);
        $this->assertScreens($tutor, [$page => 404]);
        $this->assertScreens($this->admin, [$page => 200, '/settings/tournaments' => 200, '/settings/tournaments/new' => 200, '/settings/tournaments/'.$tournament->getId().'/edit' => 200]);
    }

    public function testTheListShowsTheTournamentsTheReaderMayRead(): void
    {
        $this->tournament(opened: true);
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/tournaments');
        self::assertSelectorTextContains('.qt-cards', 'Tournoi RGPD');

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/tournaments');
        self::assertSelectorNotExists('.qt-cards');
    }

    public function testTheStandingsPageShowsTheReaderAndTheEligibleStudents(): void
    {
        $tournament = $this->tournament(opened: true);
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/tournaments/'.$tournament->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.qt[data-tournament-palette="lavender"]');
        self::assertSelectorTextContains('.qt-me', 'Pas encore joué');
        self::assertSelectorTextContains('#qt-not-played', 'Qt.bruno Test (TEST-1)');
        self::assertSelectorTextContains('.qt-monogram', 'RGPD');
    }

    public function testTheAssemblerCountsTheLastFourteenDaysAndRemembersTheClass(): void
    {
        $tournament = $this->tournament(opened: true);
        $this->duel($tournament, $this->student, $this->otherStudent, new \DateTimeImmutable('-3 days'), 1020, 980);
        $this->duel($tournament, $this->student, $this->otherStudent, new \DateTimeImmutable('-20 days'), 1040, 960, 1020, 980);

        $view = static::getContainer()->get(QuizTournamentStandingsAssembler::class)->assemble($tournament, new \DateTimeImmutable());

        $alice = $view->lineFor($this->student->getId());
        self::assertNotNull($alice);
        self::assertSame(QuizTournamentStandingBlock::Placement, $alice->block());
        self::assertSame(2, $alice->duels());
        self::assertSame(20, $alice->delta, 'only the duel of the last 14 days moved the trend');
        self::assertSame(1, $alice->row->entry->recentDuels);
        self::assertSame(2, $alice->wins);
        self::assertSame($this->program->getId(), $alice->program?->getId());

        $player = $this->em->getRepository(QuizTournamentPlayer::class)->findOneBy(['tournament' => $tournament, 'user' => $this->student]);
        self::assertSame($this->program->getId(), $player?->getDisplayedProgram()?->getId(), 'the class shown is remembered on the player');

        // A student the targeting no longer reaches keeps their line and their last class.
        $this->program->removeStudent($this->otherStudent);
        $this->em->flush();
        $view = static::getContainer()->get(QuizTournamentStandingsAssembler::class)->assemble($tournament, new \DateTimeImmutable());
        $bruno = $view->lineFor($this->otherStudent->getId());
        self::assertNotNull($bruno);
        self::assertFalse($bruno->eligible());
        self::assertSame($this->program->getId(), $bruno->program?->getId());
    }

    private function reload(QuizTournament $tournament): QuizTournament
    {
        $reloaded = static::getContainer()->get(QuizTournamentRepository::class)->find((int) $tournament->getId());
        self::assertInstanceOf(QuizTournament::class, $reloaded);

        return $reloaded;
    }

    private function tickProgram(Form $form, Program $program): void
    {
        $boxes = $form->get('quiz_tournament[targets][targetPrograms]');
        self::assertIsArray($boxes);
        foreach ($boxes as $box) {
            if ($box instanceof ChoiceFormField && \in_array((string) $program->getId(), $box->availableOptionValues(), true)) {
                $box->tick();

                return;
            }
        }
        self::fail('The formation is not offered by the targeting field.');
    }

    private function tournament(bool $opened): QuizTournament
    {
        $tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+2 months'), QuizTournamentPalette::Lavender, $this->admin);
        $tournament->addTargetProgram($this->program);
        $tournament->addSource($this->quiz($this->admin, 'Source', qcm: 20, trueFalse: 0, ignored: 0));
        if ($opened) {
            $tournament->open($this->admin, new \DateTimeImmutable('-1 month'));
        }
        $this->em->persist($tournament);
        $this->em->flush();

        return $tournament;
    }

    private function quiz(User $owner, string $name, int $qcm, int $trueFalse, int $ignored): QuizTemplate
    {
        $quiz = new QuizTemplate($owner);
        $quiz->setName($name);
        $quiz->setCreatedBy($owner);
        $this->em->persist($quiz);
        foreach ([[QuestionType::Qcm, $qcm], [QuestionType::VraiFaux, $trueFalse], [QuestionType::Apparier, $ignored]] as [$type, $count]) {
            for ($i = 0; $i < $count; ++$i) {
                $question = new QuizQuestion($quiz);
                $question->setType($type);
                $question->setLabel($type->value.' '.$i);
                $quiz->addQuestion($question);
                $this->em->persist($question);
            }
        }
        $this->em->flush();

        return $quiz;
    }

    /**
     * A finished duel A beats B, and both players' lines moved by it.
     */
    private function duel(QuizTournament $tournament, User $a, User $b, \DateTimeImmutable $startedAt, int $afterA, int $afterB, int $beforeA = 1000, int $beforeB = 1000): void
    {
        $duel = new QuizTournamentDuel($tournament, $a, $b, QuizTournamentDuelOrigin::Challenge, 42, $startedAt, $beforeA, $beforeB, false, false);
        $duel->finish($startedAt->modify('+10 minutes'), 15.0, 10.0, $afterA, $afterB);
        $this->em->persist($duel);

        foreach ([[$a, $afterA, 1.0], [$b, $afterB, 0.0]] as [$user, $rating, $outcome]) {
            $player = $this->em->getRepository(QuizTournamentPlayer::class)->findOneBy(['tournament' => $tournament, 'user' => $user]);
            if (null === $player) {
                $player = new QuizTournamentPlayer($tournament, $user);
                $this->em->persist($player);
            }
            $player->recordDuel($rating, $outcome);
        }
        $this->em->flush();
    }
}
