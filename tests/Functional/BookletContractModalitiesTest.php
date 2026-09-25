<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ContractType;
use App\Entity\Enterprise;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipFormationCenter;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Service\InternshipBookletBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The modalités de contrat are chapter I's sections 5 onwards: the text of the alternant's own
 * contract type, the formation's if it wrote one, and nothing at all otherwise. Their headings are
 * what the sommaire and the reader's menu list, so moving one moves all three.
 *
 * Rendered for real, template included, because that is where the parts of an export are cut and
 * where a section that exists nowhere must leave no trace.
 */
class BookletContractModalitiesTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $author;
    private User $student;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'modalities.author');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'modalities.student');
        $this->program = $this->createProgram([$this->student], [], $this->author);

        $center = new InternshipFormationCenter();
        $center->setCreatedBy($this->author);
        $this->entityManager->persist($center);
        $this->entityManager->flush();
    }

    public function testWithoutAnyTextChapterOneStopsAtTheTeam(): void
    {
        $html = $this->render($this->tutorLink(ContractTypeCode::Apprentissage));

        self::assertStringNotContainsString('class="lv-free-text"', $html);
        self::assertStringNotContainsString('section-i-5', $html);
        self::assertStringContainsString('Les signataires certifient', $html);
    }

    public function testTheContractTypesDefaultIsPrintedAsNumberedSections(): void
    {
        $this->contractType(ContractTypeCode::Apprentissage, '<h1>5. Modalités</h1><p>Texte.</p><h2>Détail</h2><h1>Durée de travail</h1>');

        $html = $this->render($this->tutorLink(ContractTypeCode::Apprentissage));

        self::assertStringContainsString('<h2 class="lv-h2" id="section-i-5">5. Modalités</h2><p>Texte.</p><h3 class="lv-h3">Détail</h3><h2 class="lv-h2" id="section-i-6">6. Durée de travail</h2>', $html);
        // The sommaire lists them, not the subtitle.
        self::assertStringContainsString('<a href="#section-i-6"', $html);
        self::assertStringNotContainsString('Détail</span>', $html);
    }

    public function testTheFormationsOwnTextWins(): void
    {
        $contractType = $this->contractType(ContractTypeCode::Apprentissage, '<h2>Texte du centre</h2>');
        $override = new ProgramContractModality($this->program, $contractType, '<h2>Texte de la formation</h2>');
        $override->setCreatedBy($this->author);
        $this->entityManager->persist($override);
        $this->entityManager->flush();

        $html = $this->render($this->tutorLink(ContractTypeCode::Apprentissage));

        self::assertStringContainsString('5. Texte de la formation', $html);
        self::assertStringNotContainsString('Texte du centre', $html);
    }

    public function testAnotherContractTypesTextIsNotPrinted(): void
    {
        $this->contractType(ContractTypeCode::Apprentissage, '<h2>Apprentissage seulement</h2>');

        $html = $this->render($this->tutorLink(ContractTypeCode::Professionnalisation));

        self::assertStringNotContainsString('Apprentissage seulement', $html);
    }

    public function testEachPartOfAnExportCarriesOnlyItsOwnPages(): void
    {
        $this->contractType(ContractTypeCode::Apprentissage, '<h2>Modalités</h2>');
        $tutorLink = $this->tutorLink(ContractTypeCode::Apprentissage);
        $period = $this->period();

        $before = $this->render($tutorLink, ['pdfExport' => true, 'bookletSlice' => 'before']);
        self::assertStringContainsString('Sommaire', $before);
        self::assertStringContainsString('5. Modalités', $before);
        self::assertStringNotContainsString('id="section-iii"', $before);
        // The hidden links the page measurement reads.
        self::assertStringContainsString('<nav class="lv-anchor-links"', $before);

        $after = $this->render($tutorLink, ['pdfExport' => true, 'bookletSlice' => 'after', 'pageOffset' => 11]);
        self::assertStringNotContainsString('Sommaire', $after);
        self::assertStringContainsString('id="section-iii"', $after);
        self::assertStringContainsString('html { counter-reset: page 11; }', $after);

        $extract = $this->render($tutorLink, ['pdfExport' => true, 'bookletSlice' => 'period', 'bookletPartialPeriodId' => $period->getId(), 'pageOffset' => 11]);
        self::assertStringContainsString('id="section-period-1"', $extract);
        self::assertStringNotContainsString('Sommaire', $extract);
        self::assertStringNotContainsString('id="section-iii"', $extract);
    }

    /** The reader's menu reads the same outline: the modalités' sections appear there too. */
    public function testTheReadersMenuListsTheSameSections(): void
    {
        $this->contractType(ContractTypeCode::Apprentissage, '<h2>Modalités</h2><h2>Durée de travail</h2>');
        $tutorLink = $this->tutorLink(ContractTypeCode::Apprentissage);
        $this->period();

        $this->client->loginUser($this->author);
        $crawler = $this->client->request('GET', '/ufa/alternances/'.$tutorLink->getId().'/booklet');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-livret-reader-target="toc"] a')->each(static fn ($link): string => trim($link->text()));
        self::assertContains('5. Modalités', $menu);
        self::assertContains('6. Durée de travail', $menu);
        self::assertContains('Période 1', $menu);
    }

    /** @param array<string, mixed> $part */
    private function render(InternshipTutorLink $tutorLink, array $part = []): string
    {
        $this->entityManager->clear();
        $tutorLink = $this->entityManager->find(InternshipTutorLink::class, $tutorLink->getId());
        self::assertInstanceOf(InternshipTutorLink::class, $tutorLink);

        $data = static::getContainer()->get(InternshipBookletBuilder::class)->build($tutorLink);

        return static::getContainer()->get(Environment::class)->render('internship/booklet.html.twig', $part + $data);
    }

    private function contractType(ContractTypeCode $code, string $defaultHtml): ContractType
    {
        $contractType = (new ContractType($code))->setDefaultModalitiesHtml($defaultHtml);
        $contractType->setCreatedBy($this->author);
        $this->entityManager->persist($contractType);
        $this->entityManager->flush();

        return $contractType;
    }

    private function tutorLink(ContractTypeCode $contractType): InternshipTutorLink
    {
        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->author);
        $this->entityManager->persist($enterprise);

        $tutorLink = new InternshipTutorLink($this->program);
        $tutorLink->setStudent($this->student)
            ->setTutor($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'modalities.tutor'))
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'))
            ->setContractType($contractType);
        $tutorLink->setCreatedBy($this->author);
        $this->entityManager->persist($tutorLink);
        $this->entityManager->flush();

        return $tutorLink;
    }

    private function period(): InternshipEvaluationPeriod
    {
        $period = new InternshipEvaluationPeriod($this->program);
        $period->setName('Période 1')
            ->setStartDate(new \DateTimeImmutable('-1 week'))
            ->setEndDate(new \DateTimeImmutable('+1 week'));
        $period->setCreatedBy($this->author);
        $this->entityManager->persist($period);
        $this->entityManager->flush();

        return $period;
    }
}
