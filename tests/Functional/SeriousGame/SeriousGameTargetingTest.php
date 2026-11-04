<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameProgress;
use App\Entity\SeriousGameRoleSetting;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameRoleSettingRepository;
use App\Service\SeriousGame\SeriousGameStudentReach;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The serious games opened to students by targeting (design/validated/quiz-tournament.md §14): a
 * student plays a published game whose targeting reaches them, and no other; an empty targeting
 * closes the game to every student; the adults keep the role matrix, which has lost its student
 * column. The administrator sets the targeting in Paramètres › Serious games and reads whom it
 * reaches today.
 */
class SeriousGameTargetingTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private EntityManagerInterface $em;
    private User $alice;
    private User $bruno;
    private User $teacher;
    private User $admin;
    private Program $targeted;
    private Program $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'sg.target.admin');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'sg.alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'sg.bruno');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'sg.teacher');
        $this->targeted = $this->createProgram([$this->alice], [$this->teacher], $this->admin);
        $this->other = $this->createProgram([$this->bruno], [], $this->admin);
    }

    public function testAStudentPlaysAGameWhoseTargetingReachesThemAndNoOther(): void
    {
        $game = $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $this->game()->addTargetProgram($this->program($this->targeted));
        $this->em->flush();

        $this->assertScreens($this->alice, ['/serious-games' => 200, '/serious-games/play/observatoire' => 200]);
        $this->assertScreens($this->bruno, ['/serious-games' => 404, '/serious-games/play/observatoire' => 404]);
        self::assertNotNull($game->getId());
    }

    public function testATargetedSectionReachesEveryFormationUnderIt(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $section = $this->program($this->other)->getCohort()?->getTrack()?->getSection() ?? self::fail('section');
        $this->game()->addTargetSection($section);
        $this->em->flush();

        $this->assertScreens($this->bruno, ['/serious-games/play/observatoire' => 200]);
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 404]);
    }

    public function testAnEmptyTargetingClosesTheGameToEveryStudentAndLeavesTheAdultsTheMatrix(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);

        $this->assertScreens($this->alice, ['/serious-games' => 404, '/serious-games/play/observatoire' => 404]);
        $this->assertScreens($this->bruno, ['/serious-games' => 404]);
        $this->assertScreens($this->teacher, ['/serious-games' => 200, '/serious-games/play/observatoire' => 200]);

        // A leftover student cell of the matrix opens nothing any more.
        $this->em->persist(new SeriousGameRoleSetting($this->game(), 'ROLE_STUDENT', true));
        $this->em->flush();
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 404]);

        // The targeting reaches the teacher's class: the teacher still plays by the matrix alone.
        $this->em->createQuery('UPDATE App\Entity\SeriousGameRoleSetting s SET s.enabled = false')->execute();
        $this->game()->addTargetProgram($this->program($this->targeted));
        $this->em->flush();
        $this->assertScreens($this->teacher, ['/serious-games/play/observatoire' => 404]);
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 200]);
    }

    public function testAStudentWhoAlsoTeachesPlaysByTheAdultCell(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $both = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_TEACHER'], 'sg.both');

        $this->assertScreens($both, ['/serious-games/play/observatoire' => 200]);
    }

    public function testTheMatrixHasNoStudentColumnAndWritesNoStudentCell(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);

        $this->assertScreens($this->admin, ['/features' => 200]);
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Observatoire — ROLE_TEACHER"', $page);
        self::assertStringNotContainsString('Observatoire — ROLE_STUDENT"', $page);

        $this->client->request('POST', '/features/serious-games/observatoire', [
            '_token' => $this->csrfToken('serious-game-matrix-observatoire'),
            'roles' => ['ROLE_STUDENT' => '1', 'ROLE_STAFF' => '1'],
        ]);
        self::assertResponseRedirects();
        $switches = static::getContainer()->get(SeriousGameRoleSettingRepository::class)->matrix()[(int) $this->game()->getId()] ?? [];
        self::assertArrayNotHasKey('ROLE_STUDENT', $switches);
        self::assertTrue($switches['ROLE_STAFF']);
        self::assertFalse($switches['ROLE_TEACHER']);
    }

    public function testTheReachCountsTheStudentsPerFormation(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $reach = static::getContainer()->get(SeriousGameStudentReach::class);
        $before = $reach->of($this->game());
        self::assertSame(['total' => 0, 'programs' => []], $before);

        $carla = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'sg.carla');
        $this->program($this->targeted)->addStudent($carla);
        $this->game()->addTargetProgram($this->program($this->targeted));
        $this->game()->addTargetProgram($this->program($this->other));
        $this->em->flush();

        $game = $this->game();
        $summary = $reach->of($game);
        self::assertSame(3, $summary['total']);
        $counts = [];
        foreach ($summary['programs'] as $row) {
            $counts[(int) $row['program']->getId()] = $row['students'];
        }
        self::assertSame([(int) $this->targeted->getId() => 2, (int) $this->other->getId() => 1], $counts + []);
        self::assertSame(3, $reach->count($this->game()));
    }

    public function testTheAdministratorTargetsAGameAndReadsWhomItReaches(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $url = '/settings/serious-games/observatoire/students';

        $this->assertScreens($this->admin, ['/settings/serious-games' => 200, $url => 200]);
        self::assertStringContainsString('aucun étudiant', (string) $this->client->getResponse()->getContent());

        $form = $this->client->getCrawler()->filter('form[name="serious_game_targeting"]')->form();
        $this->boxOf($form, $this->targeted)->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1 étudiant', $page);
        self::assertStringContainsString('TEST-1', $page);

        $this->em->clear();
        $game = static::getContainer()->get(SeriousGameRepository::class)->findOneBySlug('observatoire') ?? self::fail('game');
        self::assertSame([(int) $this->targeted->getId()], array_map(static fn (Program $p): int => (int) $p->getId(), $game->getTargetPrograms()->toArray()));
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 200]);

        // Emptied again: allowed, and the game is closed to every student.
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $url);
        $form = $this->client->getCrawler()->filter('form[name="serious_game_targeting"]')->form();
        $this->boxOf($form, $this->targeted)->untick();
        $this->client->submit($form);
        self::assertResponseRedirects($url);
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 404]);

        // Paramètres is the administrators' (access_control): everybody else is refused at the door.
        $this->assertScreens($this->teacher, [$url => 403]);
        $this->assertScreens($this->alice, [$url => 403]);
    }

    public function testTheMigrationRemovesTheStudentCellsAndKeepsEverythingElse(): void
    {
        $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $game = $this->game();
        $this->em->persist(new SeriousGameRoleSetting($game, 'ROLE_STUDENT', true));
        $this->em->persist(new SeriousGameProgress($this->em->getReference(User::class, $this->alice->getId()), $game));
        $this->em->flush();

        // Migrations are not autoloaded: the class is loaded from its file and named after it.
        $file = static::getContainer()->getParameter('kernel.project_dir').'/migrations/Version20261104163800.php';
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->em->getConnection(), new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->em->getConnection()->executeStatement($query->getStatement());
        }

        $switches = static::getContainer()->get(SeriousGameRoleSettingRepository::class)->matrix()[(int) $game->getId()] ?? [];
        self::assertSame(['ROLE_TEACHER' => true], $switches);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM serious_game_section') + $this->rowCount('SELECT COUNT(*) FROM serious_game_program'));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM serious_game_progress WHERE player_id = '.(int) $this->alice->getId()));
        $this->assertScreens($this->alice, ['/serious-games/play/observatoire' => 404]);
    }

    private function boxOf(Form $form, Program $program): ChoiceFormField
    {
        $boxes = $form->get('serious_game_targeting[targets][targetPrograms]');
        foreach (\is_array($boxes) ? $boxes : [$boxes] as $box) {
            if ($box instanceof ChoiceFormField && \in_array((string) $program->getId(), $box->availableOptionValues(), true)) {
                return $box;
            }
        }

        self::fail('The formation is not offered by the targeting field.');
    }

    private function rowCount(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function game(): SeriousGame
    {
        return static::getContainer()->get(SeriousGameRepository::class)->findOneBySlug('observatoire') ?? self::fail('game');
    }

    private function program(Program $program): Program
    {
        return $this->em->getRepository(Program::class)->find($program->getId()) ?? self::fail('program');
    }
}
