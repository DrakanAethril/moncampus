<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Hands over to the next role, by mail, when a signature has just been affixed to an evaluation
 * period: the tutor signs -> the apprentice is told it is their turn. The apprentice's own
 * signature hands the period over to the formation centre, which is not mailed - the teaching
 * team's step, the one this used to announce, is no longer part of the chain.
 *
 * A separate service rather than code in the controller: that signature can be affixed from two
 * screens (the tutor themselves, or the staff acting on their behalf).
 *
 * Nothing is sent - silently - to an apprentice with no contact address: that is a gap in a
 * record, not something the signatory can fix, and failing would block an otherwise valid
 * signature. Same rule as AlternanceReminderService::sendSingle() and
 * AlternanceEngagementService::invite().
 */
class AlternancePeriodChainNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The tutor has just signed: the apprentice's turn.
    public function notifyStudentAfterTutorSignature(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): void
    {
        $contactEmail = $tutorLink->getStudent()?->getContactEmail();

        if (null === $contactEmail) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to($contactEmail)
            ->subject($this->translator->trans('ufaAlternancePeriodTurnEmailSubject', ['%period%' => $period->getName()]))
            ->htmlTemplate('emails/internship_alternance_period_turn.html.twig')
            ->context([
                'periodName' => $period->getName(),
                'ctaRoute' => 'app_program_internship_my_evaluations',
                'ctaRouteParams' => ['id' => $tutorLink->getProgram()->getId()],
            ]));
    }
}
