<?php

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipReminder;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\AlternanceReminderStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends + logs an alternance follow-up reminder - generalizes
 * ProgramInternshipController::sendEvaluationReminders()'s loop-and-mailer->send() pattern to (a)
 * work across every alternance Program at once (26i, this feature's dashboard is cross-Program)
 * and (b) always persist an InternshipReminder row, which the older code never did. Every send
 * here is staff-triggered (decision #2 in the feature's plan doc) - $auto on the logged row is
 * always false, there is no scheduled/cron caller of this service.
 */
class AlternanceReminderService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly AlternancePeriodStatusResolver $statusResolver,
    ) {
    }

    /**
     * @param list<'tutor'|'supervisor'> $ccRoles
     */
    public function sendSingle(InternshipTutorLink $tutorLink, AlternanceReminderStep $step, ?InternshipEvaluationPeriod $period, array $ccRoles, User $sentBy): InternshipReminder
    {
        $recipientEmail = $this->emailForStep($tutorLink, $step);
        if (null === $recipientEmail) {
            throw new \LogicException(\sprintf('No recipient e-mail resolvable for alternance #%d, step "%s".', (int) $tutorLink->getId(), $step->value));
        }

        $email = (new TemplatedEmail())
            ->to($recipientEmail)
            ->subject($this->translator->trans('ufaAlternanceReminderEmailSubject'))
            ->htmlTemplate('emails/internship_alternance_reminder.html.twig')
            ->context([
                'recipientFirstName' => $this->firstNameForStep($tutorLink, $step),
                'period' => $period,
                'ctaRoute' => $this->ctaRouteForStep($step),
                // No route needs params yet (app_internship_tutor_home / app_home) - will gain
                // tutorLinkId/periodId once Phase 5/6 wires this into the real per-role wizard
                // and suivi routes.
                'ctaRouteParams' => [],
            ]);

        foreach ($ccRoles as $ccRole) {
            $ccEmail = 'tutor' === $ccRole ? ($tutorLink->getTutor()?->getEmail() ?? $tutorLink->getTutorEmail()) : $tutorLink->getSupervisor()?->getEmail();
            if (null !== $ccEmail) {
                $email->addCc($ccEmail);
            }
        }

        $this->mailer->send($email);

        $reminder = new InternshipReminder($tutorLink, $step, $sentBy, $period);
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        return $reminder;
    }

    // Sends to every link among $tutorLinks whose current status for $period is genuinely the
    // tutor's or the student's turn (the only two roles 26i's "non-soumis" list targets, per the
    // spec - team/supervisor/engagement steps aren't part of the grouped relance screen).
    /**
     * @param list<InternshipTutorLink> $tutorLinks
     */
    public function sendBulkForPeriod(InternshipEvaluationPeriod $period, array $tutorLinks, User $sentBy): int
    {
        $sent = 0;

        foreach ($tutorLinks as $tutorLink) {
            $status = $this->statusResolver->resolveStepForPeriod($tutorLink, $period);
            $step = match ($status->step) {
                AlternanceStepStatus::STEP_TUTOR => AlternanceReminderStep::Tutor,
                AlternanceStepStatus::STEP_STUDENT => AlternanceReminderStep::Student,
                default => null,
            };

            if (null === $step) {
                continue;
            }

            $this->sendSingle($tutorLink, $step, $period, [], $sentBy);
            ++$sent;
        }

        return $sent;
    }

    private function emailForStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): ?string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => $tutorLink->getTutor()?->getEmail() ?? $tutorLink->getTutorEmail(),
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => $tutorLink->getStudent()?->getEmail(),
            AlternanceReminderStep::Supervisor => $tutorLink->getSupervisor()?->getEmail(),
            AlternanceReminderStep::Team, AlternanceReminderStep::EngagementCenter => null,
        };
    }

    private function firstNameForStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => $tutorLink->getTutor()?->getFirstName() ?? $tutorLink->getTutorFirstName(),
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => $tutorLink->getStudent()?->getFirstName() ?? '',
            AlternanceReminderStep::Supervisor => $tutorLink->getSupervisor()?->getFirstName() ?? '',
            AlternanceReminderStep::Team, AlternanceReminderStep::EngagementCenter => '',
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
