<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcfBooklet;
use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\Modality;
use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use App\Entity\ProgramStudentModality;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Enum\EcfPart;
use App\Repository\EcfBookletRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ECF booklet's screens against the real schema (design/validated/ecf-booklet.md): an
 * administrator reads and writes, the administration reads only, nobody else reaches it, and a
 * formation that does not keep the booklet shows none.
 */
class EcfBookletTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $staff;
    private User $teacher;
    private User $student;
    private Program $program;
    private InternshipTutorLink $tutorLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'ecf.admin');
        $this->staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'ecf.staff');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'ecf.teacher');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'ecf.student');
        $this->program = $this->createProgram([$this->student], [$this->teacher], $this->admin);

        foreach (['AT1' => 'Développer une application sécurisée', 'AT2' => 'Concevoir une application en couches'] as $code => $label) {
            $group = (new SkillGroup($label, $this->program))->setCode($code);
            $group->setCreatedBy($this->admin);
            $this->entityManager->persist($group);
            $skill = new Skill('Compétence de '.$code, $group);
            $skill->setCreatedBy($this->admin);
            $this->entityManager->persist($skill);
        }

        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->admin);
        $this->entityManager->persist($enterprise);
        $this->tutorLink = (new InternshipTutorLink($this->program))
            ->setStudent($this->student)
            ->setTutor($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'ecf.tutor'))
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'))
            ->setContractType(ContractTypeCode::Apprentissage);
        $this->tutorLink->setCreatedBy($this->admin);
        $this->entityManager->persist($this->tutorLink);
        $this->entityManager->flush();
    }

    private function enable(): void
    {
        $settings = (new ProgramEcfSettings($this->program))->setEnabled(true)->setTitleLabel('Concepteur développeur d’applications')->setTitleCode('TP-01281')->setMillesime('04');
        $settings->setCreatedBy($this->admin);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function screens(): array
    {
        $id = $this->tutorLink->getId();

        return [
            sprintf('/ufa/alternances/%d/ecf', $id),
            sprintf('/ufa/alternances/%d/ecf/cover', $id),
            sprintf('/ufa/alternances/%d/ecf/activities/AT1', $id),
            sprintf('/ufa/alternances/%d/ecf/synthesis', $id),
            sprintf('/ufa/alternances/%d/ecf/read', $id),
            sprintf('/ufa/alternances/%d/ecf/frame', $id),
        ];
    }

    public function testAFormationThatDoesNotKeepTheBookletShowsNone(): void
    {
        $this->assertScreens($this->admin, array_fill_keys($this->screens(), 404));

        $this->client->request('GET', sprintf('/ufa/alternances/%d', $this->tutorLink->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.cm-ecf-chips');
    }

    public function testTheAdministrationReadsAndNobodyElseReaches(): void
    {
        $this->enable();

        $this->assertScreens($this->admin, array_fill_keys($this->screens(), 200));
        $this->assertScreens($this->staff, array_fill_keys($this->screens(), 200));
        $this->assertScreens($this->teacher, array_fill_keys($this->screens(), 403));
        $this->assertScreens($this->student, array_fill_keys($this->screens(), 403));
        $this->assertScreens($this->admin, [sprintf('/ufa/alternances/%d/ecf/activities/AT9', $this->tutorLink->getId()) => 404]);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', sprintf('/ufa/alternances/%d', $this->tutorLink->getId()));
        self::assertSelectorCount(3, '.cm-ecf-chip');
    }

    public function testAnAdministratorWritesAndSignsAndStaffCannot(): void
    {
        $this->enable();
        $id = $this->tutorLink->getId();
        $path = sprintf('/ufa/alternances/%d/ecf/activities/AT1', $id);
        $rows = ['rows' => [['description' => "Installation de Docker\nGithub", 'date' => '2025-02-07', 'competences' => ['1']]], 'result' => 'satisfied', 'part' => 'main'];

        $this->client->loginUser($this->staff);
        $this->client->request('GET', $path);
        $this->client->request('POST', $path, $rows + ['_token' => $this->csrfToken('ufa_ecf')]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->booklet());

        $this->client->loginUser($this->admin);
        $this->client->request('GET', $path);
        $this->client->request('POST', $path, $rows + ['_token' => $this->csrfToken('ufa_ecf')]);
        self::assertResponseRedirects();
        $booklet = $this->booklet();
        self::assertNotNull($booklet);
        self::assertSame(['Installation de Docker', 'Github'], $booklet->activityFor('AT1')?->rowsOf(EcfPart::Main)[0]->paragraphs());

        $this->client->request('POST', sprintf('/ufa/alternances/%d/ecf/sign', $id), ['_token' => $this->csrfToken('ufa_ecf'), 'part' => 'main', 'code' => 'AT1', 'slot' => 'evaluator_1', 'evaluatedOn' => '2025-02-07']);
        self::assertResponseRedirects();
        $booklet = $this->booklet();
        self::assertNotNull($booklet);
        self::assertCount(1, $booklet->getVisas());
        self::assertSame('Développer une application sécurisée', $booklet->activityFor('AT1')?->getFrozenLabel());

        $this->client->request('GET', $path);
        self::assertSelectorTextContains('.cm-ecf-visa__stamp', 'Signé numériquement le '.(new \DateTimeImmutable())->format('d/m/Y'));

        // The printed document says the same, in the ministry's words.
        $this->client->request('GET', sprintf('/ufa/alternances/%d/ecf/frame', $id));
        self::assertSelectorTextContains('#ecf-at-1 .atline', 'Activité-type 1');
        self::assertSelectorTextContains('#ecf-at-1 .sig__visa', 'Signé numériquement le '.(new \DateTimeImmutable())->format('d/m/Y'));
        self::assertSelectorTextContains('#ecf-at-1 .result', '☒');
    }

    private function booklet(): ?EcfBooklet
    {
        $this->entityManager->clear();

        return static::getContainer()->get(EcfBookletRepository::class)->findOneBy(['titleCode' => 'TP-01281']);
    }

    public function testTheStaffOffersTheClosedBookletAndTheStudentSignsItFromMonAlternance(): void
    {
        $this->enable();
        $modality = (new Modality('Alternance', '#445566'))->setIsAlternance(true);
        $modality->addProgram($this->program);
        $modality->setCreatedBy($this->admin);
        $this->entityManager->persist($modality);
        $this->entityManager->persist(new ProgramStudentModality($this->program, $this->student, $modality));
        $booklet = new EcfBooklet($this->student, 'TP-01281', '04');
        $booklet->setCreatedBy($this->admin);
        $booklet->setClosedAt(new \DateTimeImmutable());
        $this->entityManager->persist($booklet);
        $this->entityManager->flush();
        $id = $this->tutorLink->getId();

        // Nothing for the student before the offer.
        $this->assertScreens($this->student, ['/my/alternance/ecf' => 404]);

        $this->client->loginUser($this->staff);
        $this->client->request('GET', sprintf('/ufa/alternances/%d/ecf/synthesis', $id));
        self::assertSelectorTextContains('#candidate', 'Proposer la signature à l’étudiant');
        $this->client->request('POST', sprintf('/ufa/alternances/%d/ecf/offer', $id), ['_token' => $this->csrfToken('ufa_ecf')]);
        self::assertResponseRedirects();

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/my/alternance');
        self::assertSelectorTextContains('body', 'Votre livret d’évaluations est à signer');
        $this->client->request('GET', '/my/alternance/ecf');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/my/alternance/ecf/sign', ['_token' => $this->csrfToken('my_ecf_sign')]);
        self::assertResponseRedirects('/my/alternance/ecf');

        $signedOn = (new \DateTimeImmutable())->format('d/m/Y');
        $this->client->request('GET', '/my/alternance/ecf/frame');
        self::assertSelectorTextContains('.remise', 'Signé le '.$signedOn.' par');
        self::assertSelectorTextContains('.remise', 'contre signature le '.$signedOn);

        $this->client->loginUser($this->staff);
        $this->client->request('GET', sprintf('/ufa/alternances/%d/ecf/synthesis', $id));
        self::assertSelectorTextContains('#candidate', 'Signé le '.$signedOn);
    }
}
