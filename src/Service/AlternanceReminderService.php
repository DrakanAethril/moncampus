<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipReminder;
use App\Entity\InternshipReminderBatch;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\AlternanceReminderStep;
use App\Enum\UfaActivityType;
use App\Service\ReminderBatch\ReminderDelivery;
use App\Service\ReminderBatch\ReminderDeliveryException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends + logs an alternance follow-up reminder - one at a time from the alternance's page, or a
 * line of a reminder batch (InternshipReminderBatch) - and always persists an InternshipReminder
 * row, which the older Program\InternshipReminderController never did. Every send is decided by a
 * person (decision #2 in the feature's plan doc): a batch's lines are sent by the scheduled pass,
 * but in the name of whoever launched the batch, so $auto on the logged row stays false.
 */
class AlternanceReminderService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly UfaActivityRecorder $activityRecorder,
        private readonly TransportInterface $transport,
    ) {
    }

    /**
     * Returns null - sending nothing and logging no InternshipReminder - when the person this step
     * is aimed at has no contact e-mail. Silent by design: a missing address is a gap in someone's
     * profile, not something the staff member clicking "Relancer" can fix, and an InternshipReminder
     * row would claim a relance went out when none did.
     *
     * @param list<'tutor'|'supervisor'> $ccRoles
     */
    public function sendSingle(InternshipTutorLink $tutorLink, AlternanceReminderStep $step, ?InternshipEvaluationPeriod $period, array $ccRoles, User $sentBy): ?InternshipReminder
    {
        $recipients = $this->recipients($tutorLink, $step);
        if (null === $recipients['to']) {
            return null;
        }

        $this->mailer->send($this->buildEmail($tutorLink, $step, $period, $recipients, $ccRoles));

        return $this->log($tutorLink, $step, $period, $sentBy, null);
    }

    /**
     * One line of a reminder batch, sent by the scheduled pass (ReminderBatchSender) in the name of
     * whoever launched the batch (R4). Through the transport rather than the mailer, to get SES's
     * identifier back; and loud where sendSingle() is silent - a line has to say why it did not
     * leave, and whether trying again could help.
     *
     * @throws ReminderDeliveryException
     */
    public function sendForBatch(InternshipReminderBatch $batch, InternshipTutorLink $tutorLink, ?InternshipEvaluationPeriod $period, User $sentBy): ReminderDelivery
    {
        $step = $batch->getStep()->reminderStep();
        $recipients = $this->recipients($tutorLink, $step);
        if (null === $recipients['to']) {
            throw ReminderDeliveryException::noAddress();
        }

        try {
            $email = $this->buildEmail($tutorLink, $step, $period, $recipients, []);
            $sent = $this->transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw ReminderDeliveryException::fromTransport($exception);
        } catch (RfcComplianceException $exception) {
            throw ReminderDeliveryException::invalidAddress($exception);
        }

        $providerId = $sent?->getMessageId();

        return new ReminderDelivery(
            $this->log($tutorLink, $step, $period, $sentBy, $batch),
            $recipients['to'],
            null !== $recipients['cc'],
            null !== $providerId && '' !== $providerId ? trim($providerId, '<>') : null,
        );
    }

    /**
     * Who a step's reminder goes to. The tutors' steps are the principal's to receive, the
     * secondary tutor in copy - who becomes the recipient when the principal has no address,
     * rather than nobody. A null « to » means nobody can be written to.
     *
     * @return array{to: string|null, cc: string|null}
     */
    public function recipients(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): array
    {
        $recipientEmail = $this->emailForStep($tutorLink, $step);
        if (!\in_array($step, [AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor], true)) {
            return ['to' => $recipientEmail, 'cc' => null];
        }

        $secondaryTutorEmail = $tutorLink->getSecondaryTutor()?->getContactEmail();
        if (null === $recipientEmail) {
            return ['to' => $secondaryTutorEmail, 'cc' => null];
        }

        return ['to' => $recipientEmail, 'cc' => $secondaryTutorEmail !== $recipientEmail ? $secondaryTutorEmail : null];
    }

    /**
     * @param array{to: string|null, cc: string|null} $recipients
     * @param list<'tutor'|'supervisor'>               $ccRoles
     */
    private function buildEmail(InternshipTutorLink $tutorLink, AlternanceReminderStep $step, ?InternshipEvaluationPeriod $period, array $recipients, array $ccRoles): TemplatedEmail
    {
        $recipientEmail = (string) $recipients['to'];
        $email = (new TemplatedEmail())
            ->to($recipientEmail)
            ->subject($this->translator->trans('ufaAlternanceReminderEmailSubject'))
            ->htmlTemplate('emails/internship_alternance_reminder.html.twig')
            ->context([
                'period' => $period,
                // Formatted here rather than in the template, which UFA > Configuration > Emails
                // also renders with a placeholder where the date goes.
                'deadline' => $period?->getEndDate()?->format('d/m/Y'),
                'ctaRoute' => $this->ctaRouteForStep($step),
                // No route needs params yet (app_internship_tutor_home / app_home) - will gain
                // tutorLinkId/periodId once Phase 5/6 wires this into the real per-role wizard
                // and suivi routes.
                'ctaRouteParams' => [],
            ]);

        $ccEmails = [$recipients['cc']];
        foreach ($ccRoles as $ccRole) {
            // « Le tuteur » in copy is both tutors.
            $ccEmails = 'tutor' === $ccRole
                ? [...$ccEmails, ...array_map(static fn (User $tutor): ?string => $tutor->getContactEmail(), $tutorLink->getTutors())]
                : [...$ccEmails, $tutorLink->getSupervisor()?->getContactEmail()];
        }
        foreach (array_unique(array_filter($ccEmails, static fn (?string $address): bool => null !== $address && $address !== $recipientEmail)) as $ccEmail) {
            $email->addCc($ccEmail);
        }

        return $email;
    }

    private function log(InternshipTutorLink $tutorLink, AlternanceReminderStep $step, ?InternshipEvaluationPeriod $period, User $sentBy, ?InternshipReminderBatch $batch): InternshipReminder
    {
        $reminder = new InternshipReminder($tutorLink, $step, $sentBy, $period, $batch);
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        // A deliberate duplicate of the internship_reminder row above: that one serves the detailed
        // follow-up of a reminder (recipient, copies, panel history), the log serves the single
        // chronological feed of the tracking screens. Pouring the reminder into it saves those
        // screens from having to merge two sources.
        $this->activityRecorder->record(
            UfaActivityType::ReminderSent,
            $tutorLink,
            $sentBy,
            $period,
            ['role' => $this->translator->trans($step->roleLabelKey())],
        );

        return $reminder;
    }

    // Always User::$contactEmail, never $email: the latter is the annuaire's internal address and
    // not necessarily an inbox anyone reads. A null here means "this person has no contact e-mail"
    // - see recipients().
    private function emailForStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): ?string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => $tutorLink->getTutor()?->getContactEmail(),
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => $tutorLink->getStudent()?->getContactEmail(),
            AlternanceReminderStep::Supervisor => $tutorLink->getSupervisor()?->getContactEmail(),
            AlternanceReminderStep::EngagementCenter => null,
        };
    }

    private function ctaRouteForStep(AlternanceReminderStep $step): string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => 'app_internship_tutor_home',
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => 'app_home',
            default => 'app_home',
        };
    }
}
