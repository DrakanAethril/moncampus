<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Hands over to the next role, by mail, when a signature has just been affixed to an evaluation
 * period: the tutor signs -> the apprentice is told it is their turn, the apprentice signs -> the
 * program's referent teachers are told the teaching team must fill in its part.
 *
 * A separate service rather than code in the controllers: each of these two signatures can be
 * affixed from two screens (the person themselves, or the staff acting on their behalf), that is
 * four call sites for two messages.
 *
 * Nothing is sent - silently - to anyone with no contact address: that is a gap in a record, not
 * something the signatory can fix, and failing would block an otherwise valid signature. Same rule
 * as AlternanceReminderService::sendSingle() and AlternanceEngagementService::invite().
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
        $this->send(
            $tutorLink->getStudent(),
            $tutorLink,
            $period,
            'student',
            'app_program_internship_my_evaluations',
            ['id' => $tutorLink->getProgram()->getId()],
        );
    }

    // The apprentice has just signed: the teaching team's turn, the program's referent teachers
    // being the point of contact (Program::$referentTeachers - the only nominative designation a
    // program carries; the step itself stays open to any teacher of the program). No referent
    // designated = nothing sent, like a missing address.
    public function notifyReferentTeachersAfterStudentSignature(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): void
    {
        foreach ($tutorLink->getProgram()->getReferentTeachers() as $referent) {
            $this->send($referent, $tutorLink, $period, 'team', 'app_home', []);
        }
    }

    /** @param array<string, mixed> $ctaRouteParams */
    private function send(?User $recipient, InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period, string $role, string $ctaRoute, array $ctaRouteParams): void
    {
        if (null === $recipient?->getContactEmail()) {
            return;
        }

        $student = $tutorLink->getStudent();

        $this->mailer->send((new TemplatedEmail())
            ->to($recipient->getContactEmail())
            ->subject($this->translator->trans('ufaAlternancePeriodTurnEmailSubject', ['%period%' => $period->getName()]))
            ->htmlTemplate('emails/internship_alternance_period_turn.html.twig')
            ->context([
                'role' => $role,
                'periodName' => $period->getName(),
                'studentName' => $student?->getDisplayName() ?? $student?->getUsername() ?? '',
                'programName' => $tutorLink->getProgram()->getDisplayShortName(),
                'ctaRoute' => $ctaRoute,
                'ctaRouteParams' => $ctaRouteParams,
            ]));
    }
}
