<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioClaim;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\Referential;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Entity\User;
use App\Enum\ReferentialBlockRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who reaches what in the portfolio (design/validated/portfolio.md, R2 and §14).
 *
 * The rule under test is the one decision of §1 that is easiest to break by « helping »: only a
 * teacher designated for the student's class **and option**, who still teaches there, decides. An
 * administrator reads and decides nothing; a SLAM validateur does not decide about a SISR student;
 * a teacher of the class who was not designated does not even see the area.
 */
final class PortfolioAccessTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $sisrStudent;
    private User $sisrValidator;
    private User $slamValidator;
    private User $undesignated;
    private User $departed;
    private Program $program;
    private PortfolioAchievement $achievement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'pf.admin');
        $this->sisrStudent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'pf.sisr');
        $slamStudent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'pf.slam');
        $this->sisrValidator = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'pf.v.sisr');
        $this->slamValidator = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'pf.v.slam');
        $this->undesignated = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'pf.teacher');
        $this->departed = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'pf.departed');

        $sisr = $this->option('SISR');
        $slam = $this->option('SLAM');
        $referential = $this->referential($sisr, $slam);

        $this->program = $this->createProgram([$this->sisrStudent, $slamStudent], [$this->sisrValidator, $this->slamValidator, $this->undesignated], $this->admin);
        $this->program->setPortfolioEnabled(true)->setPortfolioReferential($referential)->setPortfolioCursusYear(2);
        $this->entityManager->persist(new ProgramStudentOption($this->program, $this->sisrStudent, $sisr));
        $this->entityManager->persist(new ProgramStudentOption($this->program, $slamStudent, $slam));
        $this->entityManager->persist(new PortfolioValidator($this->program, $sisr, $this->sisrValidator, $this->admin));
        $this->entityManager->persist(new PortfolioValidator($this->program, $slam, $this->slamValidator, $this->admin));
        // Designated, but no longer among the class's teachers: the designation opens nothing.
        $this->entityManager->persist(new PortfolioValidator($this->program, $sisr, $this->departed, $this->admin));

        $portfolio = new Portfolio($this->sisrStudent, $referential);
        $this->achievement = new PortfolioAchievement($portfolio);
        $this->achievement->setTitle('Supervision Zabbix')->setStartsOn(new \DateTimeImmutable('-2 months'))->setEndsOn(new \DateTimeImmutable('-1 month'));
        new PortfolioClaim($this->achievement, $referential->getSynthesisBlock()?->getCompetencies()->first() ?: throw new \LogicException(), 'Inventaire du parc.');
        $this->achievement->submit();
        $this->entityManager->persist($portfolio);
        $this->entityManager->persist($this->achievement);
        $this->entityManager->flush();
    }

    public function testTheStudentReachesTheirPortfolio(): void
    {
        $this->assertScreens($this->sisrStudent, [
            '/my/portfolio' => 200,
            '/my/portfolio/synthesis' => 200,
            '/my/portfolio/showcases' => 200,
            '/my/portfolio/deposit' => 200,
            '/my/portfolio/achievements/new' => 200,
            '/my/portfolio/achievements/'.$this->achievement->getId() => 200,
            '/portfolios' => 404,
            '/settings/referentials' => 403,
        ]);
    }

    public function testAStudentWhoseFormationDoesNotRunThePortfolioHasNone(): void
    {
        $other = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'pf.other');

        $this->assertScreens($other, ['/my/portfolio' => 404]);
    }

    public function testTheDesignatedValidatorOfTheOptionDecides(): void
    {
        $this->assertScreens($this->sisrValidator, [
            '/portfolios' => 200,
            '/portfolios/classes' => 200,
            '/portfolios/achievements/'.$this->achievement->getId() => 200,
            '/my/portfolio' => 404,
        ]);

        $this->client->request('GET', '/portfolios/achievements/'.$this->achievement->getId());
        self::assertSelectorExists('form[action$="/decide"]');
        self::assertSelectorTextContains('body', 'Décision par compétence');
    }

    public function testTheValidatorOfAnotherOptionSeesNothingOfThisStudent(): void
    {
        $this->assertScreens($this->slamValidator, [
            '/portfolios' => 200,
            '/portfolios/achievements/'.$this->achievement->getId() => 404,
        ]);
    }

    public function testATeacherWhoWasNotDesignatedHasNoPortfolioArea(): void
    {
        $this->assertScreens($this->undesignated, [
            '/portfolios' => 404,
            '/portfolios/achievements/'.$this->achievement->getId() => 404,
        ]);
    }

    public function testADesignationOutlivingItsTeacherOpensNothing(): void
    {
        $this->assertScreens($this->departed, [
            '/portfolios' => 404,
            '/portfolios/achievements/'.$this->achievement->getId() => 404,
        ]);
    }

    public function testTheAdministratorReadsAndDecidesNothing(): void
    {
        $this->assertScreens($this->admin, [
            '/portfolios' => 302,
            '/portfolios/classes' => 200,
            '/portfolios/achievements/'.$this->achievement->getId() => 200,
            '/settings/referentials' => 200,
            '/settings/referentials/rncp' => 200,
            '/programs/'.$this->program->getId().'/settings/portfolio' => 200,
        ]);

        $this->client->request('GET', '/portfolios/achievements/'.$this->achievement->getId());
        self::assertSelectorNotExists('form[action$="/decide"]');

        $this->client->request('POST', '/portfolios/achievements/'.$this->achievement->getId().'/decide', ['decision' => 'validated']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAValidatorDecisionIsWrittenAndTheStudentReadsIt(): void
    {
        $this->client->loginUser($this->sisrValidator);
        $this->client->request('GET', '/portfolios/achievements/'.$this->achievement->getId());
        $competencyId = (int) $this->achievement->getClaims()->first()->getCompetency()?->getId();

        $this->client->request('POST', '/portfolios/achievements/'.$this->achievement->getId().'/decide', [
            '_token' => $this->csrfToken('portfolio_review'),
            'decision' => 'validated',
            'retained' => [(string) $competencyId => '1'],
        ]);
        self::assertResponseRedirects('/portfolios');

        $this->entityManager->clear();
        $achievement = $this->entityManager->find(PortfolioAchievement::class, $this->achievement->getId());
        self::assertTrue($achievement?->isValidated());
        self::assertTrue($achievement->getClaims()->first()->isRetained());
        self::assertCount(1, $achievement->getReviews());
    }

    private function option(string $name): Option
    {
        $option = new Option($name, $name, '#445566');
        $option->setCreatedBy($this->admin);
        $this->entityManager->persist($option);

        return $option;
    }

    private function referential(Option $sisr, Option $slam): Referential
    {
        $referential = new Referential('bts-sio', 'BTS SIO', 'RNCP-TEST');
        $referential->setCreatedBy($this->admin);
        $bloc1 = new ReferentialBlock($referential, 'B1', 'Support', 0);
        $bloc1->setRole(ReferentialBlockRole::Synthesis);
        new ReferentialCompetency($bloc1, 'B1.1', 'Gérer le patrimoine informatique', [], 0);
        new ReferentialCompetency($bloc1, 'B1.2', 'Travailler en mode projet', [], 1);
        $sisrBlock = new ReferentialBlock($referential, 'B2', 'Administration des systèmes et des réseaux (Option A)', 1);
        $sisrBlock->setRole(ReferentialBlockRole::Showcase)->addOption($sisr);
        new ReferentialCompetency($sisrBlock, 'B2.1', 'Concevoir une solution d’infrastructure réseau', [], 0);
        $slamBlock = new ReferentialBlock($referential, 'B3', 'Conception et développement d’applications (Option B)', 2);
        $slamBlock->setRole(ReferentialBlockRole::Showcase)->addOption($slam);
        new ReferentialCompetency($slamBlock, 'B3.1', 'Gérer les données', [], 0);
        $this->entityManager->persist($referential);

        return $referential;
    }
}
