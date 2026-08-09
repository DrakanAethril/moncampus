<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipReminder;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\AlternanceReminderStep;
use App\Enum\UfaActivityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends + logs an alternance follow-up reminder - generalizes
 * Program\InternshipReminderController::sendEvaluationReminders()'s loop-and-mailer->send() pattern to (a)
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
        private readonly UfaActivityRecorder $activityRecorder,
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
        $recipientEmail = $this->emailForStep($tutorLink, $step);
        if (null === $recipientEmail) {
            return null;
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
            $ccEmail = 'tutor' === $ccRole ? $tutorLink->getTutor()?->getContactEmail() : $tutorLink->getSupervisor()?->getContactEmail();
            if (null !== $ccEmail) {
                $email->addCc($ccEmail);
            }
        }

        $this->mailer->send($email);

        $reminder = new InternshipReminder($tutorLink, $step, $sentBy, $period);
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        // Doublon assumé avec la ligne internship_reminder ci-dessus : celle-ci sert le suivi
        // détaillé d'une relance (destinataire, copies, historique du panneau), le journal sert le
        // flux chronologique unique des écrans de suivi. Y verser la relance évite à ces écrans
        // d'avoir à fusionner deux sources.
        $this->activityRecorder->record(
            UfaActivityType::ReminderSent,
            $tutorLink,
            $sentBy,
            $period,
            ['role' => $this->translator->trans($step->roleLabelKey())],
        );

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

            // Counts what actually went out, not what was attempted - a recipient with no contact
            // e-mail is skipped by sendSingle() and must not inflate the "N relances envoyées"
            // figure the staff member is shown.
            if (null !== $this->sendSingle($tutorLink, $step, $period, [], $sentBy)) {
                ++$sent;
            }
        }

        return $sent;
    }

    // Always User::$contactEmail, never $email: the latter is the annuaire's internal address and
    // not necessarily an inbox anyone reads. A null here means "this person has no contact e-mail"
    // and the caller skips them - see sendSingle()/sendBulkForPeriod().
    private function emailForStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): ?string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => $tutorLink->getTutor()?->getContactEmail(),
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => $tutorLink->getStudent()?->getContactEmail(),
            AlternanceReminderStep::Supervisor => $tutorLink->getSupervisor()?->getContactEmail(),
            AlternanceReminderStep::Team, AlternanceReminderStep::EngagementCenter => null,
        };
    }

    private function firstNameForStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): string
    {
        return match ($step) {
            AlternanceReminderStep::EngagementTutor, AlternanceReminderStep::Tutor => $tutorLink->getTutor()?->getFirstname() ?? '',
            AlternanceReminderStep::EngagementStudent, AlternanceReminderStep::Student => $tutorLink->getStudent()?->getFirstname() ?? '',
            AlternanceReminderStep::Supervisor => $tutorLink->getSupervisor()?->getFirstname() ?? '',
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
