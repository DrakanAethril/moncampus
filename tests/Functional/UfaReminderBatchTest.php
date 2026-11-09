<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipReminder;
use App\Entity\InternshipReminderBatch;
use App\Entity\InternshipReminderBatchItem;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Modality;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\ReminderBatchExclusion;
use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStatus;
use App\Service\ReminderBatch\ReminderBatchSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * UFA › Relances, end to end (design/validated/ufa-reminder-batches.md): a batch freezes who its
 * step waits on (R1, R9), the pass sends at the platform's pace within the sending hours, reads
 * each line again just before its mail (R2), never sends one line twice (R6), and a person held by
 * a running batch is not taken by another (R3).
 *
 * Three alternances wait on their tutor for bilan 1: Ada's tutor has an address, Bob's has none,
 * Cleo's has one and will sign between the launch and the pass. 2026-11-09 is a Monday.
 */
class UfaReminderBatchTest extends FunctionalTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $entityManager;
    private User $admin;
    private Program $program;
    private InternshipEvaluationPeriod $period;
    /** @var array<string, InternshipTutorLink> */
    private array $links = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-11-09 10:00:00');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'batch.admin');
        $this->program = $this->createProgram([], [], $this->admin);

        $modality = (new Modality('Alternance', '#445566'))->setIsAlternance(true);
        $modality->setCreatedBy($this->admin);
        $this->entityManager->persist($modality);
        $this->program->addModality($modality);

        $this->period = new InternshipEvaluationPeriod($this->program);
        $this->period->setName('Bilan d’automne')
            ->setStartDate(new \DateTimeImmutable('2026-10-01'))
            ->setEndDate(new \DateTimeImmutable('2026-11-30'));
        $this->period->setCreatedBy($this->admin);
        $this->entityManager->persist($this->period);

        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->admin);
        $this->entityManager->persist($enterprise);

        foreach (['ada' => 'ada.tutor@acme.test', 'bob' => null, 'cleo' => 'cleo.tutor@acme.test'] as $name => $address) {
            $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], $name);
            $tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], $name.'.tutor');
            $tutor->setContactEmail($address);
            $this->program->addStudent($student);

            $link = new InternshipTutorLink($this->program);
            $link->setStudent($student)
                ->setTutor($tutor)
                ->setEnterprise($enterprise)
                ->setContractStartDate(new \DateTimeImmutable('2026-09-01'))
                ->setContractEndDate(new \DateTimeImmutable('2027-08-31'));
            $link->setCreatedBy($this->admin);
            $this->entityManager->persist($link);

            $engagement = new InternshipLivretEngagement($link);
            $engagement->setSignedTutorAt(new \DateTimeImmutable('2026-09-10'))
                ->setSignedStudentAt(new \DateTimeImmutable('2026-09-11'))
                ->setSignedCenterAt(new \DateTimeImmutable('2026-09-12'));
            $engagement->setCreatedBy($this->admin);
            $this->entityManager->persist($engagement);

            $this->links[$name] = $link;
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
    }

    public function testTheFormCountsWhoWaitsBeforeTheClick(): void
    {
        $this->client->request('GET', '/ufa/reminder-batches/new?target=period_tutor:1&scope=pick&programs[]='.$this->program->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-rb-summary', '3 personnes attendent cette étape');
        self::assertSelectorTextContains('.cm-rb-summary', '2 relances partiront');
        self::assertSelectorTextContains('.cm-rb-summary', '1 écartée');
        self::assertSelectorExists('option[value="period_tutor:1"]');

        $this->client->request('GET', '/ufa/reminder-batches/preview?target=period_tutor:1&scope=pick&programs[]='.$this->program->getId());
        $preview = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($preview);
        self::assertSame(2, $preview['toSend']);
        self::assertSame(1, $preview['excluded']);
        self::assertIsArray($preview['byProgram']);
        self::assertSame(3, $preview['byProgram'][(string) $this->program->getId()]);
    }

    public function testALaunchFreezesTheLinesAndSendsNothingYet(): void
    {
        $batch = $this->launch(2);

        self::assertSame(ReminderBatchStatus::Running, $batch->getStatus());
        self::assertSame(1, $batch->getPeriodRank());
        self::assertSame(ReminderBatchItemStatus::Pending, $this->item($batch, 'ada')->getStatus());
        self::assertSame(ReminderBatchItemStatus::Excluded, $this->item($batch, 'bob')->getStatus());
        self::assertSame(ReminderBatchExclusion::NoAddress, $this->item($batch, 'bob')->getExclusionReason());
        self::assertSame($this->period->getId(), $this->item($batch, 'ada')->getEvaluationPeriod()?->getId());
        self::assertEmailCount(0);
    }

    public function testACountThatMovedIsShownAgainRatherThanLaunched(): void
    {
        $this->client->request('GET', '/ufa/reminder-batches/new');
        $this->client->request('POST', '/ufa/reminder-batches/new', [
            '_token' => $this->csrfToken('ufa_reminder_batch_new'),
            'target' => 'period_tutor:1',
            'scope' => 'pick',
            'programs' => [(string) $this->program->getId()],
            'expected' => '5',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.cm-rb-error', 'Le compte a changé');
        self::assertSame(0, $this->entityManager->getRepository(InternshipReminderBatch::class)->count([]));
    }

    public function testThePassSendsReadsAgainAndFinishesTheBatch(): void
    {
        $batch = $this->launch(2);

        // Cleo's tutor signs between the launch and the pass.
        $evaluation = new InternshipTutorEvaluation($this->entityManager->find(InternshipTutorLink::class, $this->links['cleo']->getId()), $this->entityManager->find(InternshipEvaluationPeriod::class, $this->period->getId()));
        $evaluation->setSignedAt(new \DateTimeImmutable('2026-11-09 09:58'));
        $evaluation->setCreatedBy($this->entityManager->find(User::class, $this->admin->getId()));
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        $report = $this->sender()->runPass();

        self::assertSame(1, $report['sent']);
        self::assertSame(1, $report['settled']);
        self::assertSame(1, $report['finished']);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['ada.tutor@acme.test'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));

        $this->entityManager->clear();
        $batch = $this->entityManager->find(InternshipReminderBatch::class, $batch->getId());
        self::assertInstanceOf(InternshipReminderBatch::class, $batch);
        self::assertSame(ReminderBatchStatus::Finished, $batch->getStatus());
        $ada = $this->item($batch, 'ada');
        self::assertSame(ReminderBatchItemStatus::Sent, $ada->getStatus());
        self::assertSame('ada.tutor@acme.test', $ada->getRecipientEmail());
        // R4: the reminder is logged like one sent by hand, in the name of whoever launched it.
        self::assertSame($batch->getId(), $ada->getReminder()?->getBatch()?->getId());
        self::assertSame($this->admin->getId(), $ada->getReminder()?->getSentBy()?->getId());
        self::assertFalse((bool) $ada->getReminder()?->isAuto());
        self::assertSame(ReminderBatchItemStatus::AlreadyDone, $this->item($batch, 'cleo')->getStatus());

        // And a second pass sends nothing more.
        $this->sender()->runPass();
        self::assertSame(1, $this->entityManager->getRepository(InternshipReminder::class)->count([]));
    }

    public function testNothingLeavesOutsideTheSendingHours(): void
    {
        $this->launch(2);
        self::mockTime('2026-11-14 10:00:00');

        $report = $this->sender()->runPass();

        self::assertSame('closed', $report['stopped']);
        self::assertSame(0, $report['sent']);
        self::assertEmailCount(0);
    }

    public function testAPausedBatchWaitsAndACancelledOneKeepsItsLines(): void
    {
        $batch = $this->launch(2);
        $token = $this->csrfToken('ufa_reminder_batch_'.$batch->getId());

        $this->client->request('POST', '/ufa/reminder-batches/'.$batch->getId().'/pause', ['_token' => $token]);
        self::assertResponseRedirects('/ufa/reminder-batches/'.$batch->getId());
        self::assertSame(0, $this->sender()->runPass()['sent']);

        $this->client->request('POST', '/ufa/reminder-batches/'.$batch->getId().'/cancel', ['_token' => $token]);
        $this->entityManager->clear();
        $batch = $this->entityManager->find(InternshipReminderBatch::class, $batch->getId());
        self::assertInstanceOf(InternshipReminderBatch::class, $batch);
        self::assertSame(ReminderBatchStatus::Cancelled, $batch->getStatus());
        self::assertSame(ReminderBatchItemStatus::Cancelled, $this->item($batch, 'ada')->getStatus());
        // R7: the set-aside line keeps its reason.
        self::assertSame(ReminderBatchItemStatus::Excluded, $this->item($batch, 'bob')->getStatus());
    }

    public function testSomebodyARunningBatchHoldsIsSetAsideByTheNextOne(): void
    {
        $this->launch(2);

        $this->client->request('GET', '/ufa/reminder-batches/preview?target=period_tutor:1&scope=pick&programs[]='.$this->program->getId());
        $preview = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($preview);
        self::assertSame(0, $preview['toSend']);
        self::assertSame(3, $preview['excluded']);
    }

    public function testALineAPassLeftBehindIsNeverSentAgain(): void
    {
        $batch = $this->launch(2);
        $ada = $this->item($batch, 'ada');
        $ada->claim(new \DateTimeImmutable('2026-11-09 09:59'));
        $this->entityManager->flush();

        $report = $this->sender()->runPass();

        self::assertSame(1, $report['uncertain']);
        $this->entityManager->clear();
        $batch = $this->entityManager->find(InternshipReminderBatch::class, $batch->getId());
        self::assertInstanceOf(InternshipReminderBatch::class, $batch);
        self::assertSame(ReminderBatchItemStatus::Uncertain, $this->item($batch, 'ada')->getStatus());
        self::assertSame(1, $report['sent'], 'Cleo is still sent; Ada never again.');
    }

    public function testTheBatchScreensRender(): void
    {
        $batch = $this->launch(2);

        $this->client->request('GET', '/ufa/reminder-batches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-rb-row', 'Bilan 1 · évaluation du tuteur');

        $this->client->request('GET', '/ufa/reminder-batches/'.$batch->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-rb-table', 'Aucune adresse de contact');

        $this->client->request('GET', '/ufa/reminder-batches/'.$batch->getId().'?filter=excluded');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.cm-rb-table tbody tr');
    }

    public function testTheOldGroupedScreenLeadsToTheBatches(): void
    {
        $this->client->request('GET', '/ufa/reminders');

        self::assertResponseRedirects('/ufa/reminder-batches', 301);
    }

    private function launch(int $expected): InternshipReminderBatch
    {
        $this->client->request('GET', '/ufa/reminder-batches/new');
        $this->client->request('POST', '/ufa/reminder-batches/new', [
            '_token' => $this->csrfToken('ufa_reminder_batch_new'),
            'target' => 'period_tutor:1',
            'scope' => 'pick',
            'programs' => [(string) $this->program->getId()],
            'expected' => (string) $expected,
        ]);
        self::assertResponseRedirects();

        $batch = $this->entityManager->getRepository(InternshipReminderBatch::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(InternshipReminderBatch::class, $batch);

        return $batch;
    }

    private function item(InternshipReminderBatch $batch, string $name): InternshipReminderBatchItem
    {
        $item = $this->entityManager->getRepository(InternshipReminderBatchItem::class)->findOneBy(['batch' => $batch, 'tutorLink' => $this->links[$name]->getId()]);
        self::assertInstanceOf(InternshipReminderBatchItem::class, $item);

        return $item;
    }

    private function sender(): ReminderBatchSender
    {
        return static::getContainer()->get(ReminderBatchSender::class);
    }
}
