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
 * Passe la main au rôle suivant, par mail, quand une signature vient d'être apposée sur une
 * période d'évaluation : le tuteur signe -> l'alternant est prévenu que c'est à lui, l'alternant
 * signe -> les enseignants référents de la formation sont prévenus que l'équipe pédagogique doit
 * remplir sa partie.
 *
 * Un service à part plutôt que du code dans les contrôleurs : chacune de ces deux signatures peut
 * être apposée depuis deux écrans (l'intéressé lui-même, ou le staff qui agit pour son compte),
 * soit quatre points d'appel pour deux messages.
 *
 * Rien n'est envoyé - silencieusement - à qui n'a pas d'adresse de contact : c'est un trou dans
 * une fiche, pas quelque chose que le signataire puisse corriger, et échouer bloquerait une
 * signature par ailleurs valide. Même règle que AlternanceReminderService::sendSingle() et
 * AlternanceEngagementService::invite().
 */
class AlternancePeriodChainNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Le tuteur vient de signer : au tour de l'alternant.
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

    // L'alternant vient de signer : au tour de l'équipe pédagogique, dont les enseignants
    // référents de la formation sont le point de contact (Program::$referentTeachers - la seule
    // désignation nominative que porte une formation ; l'étape elle-même reste ouverte à tout
    // enseignant du programme). Aucun référent désigné = aucun envoi, comme une adresse manquante.
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
                'recipientFirstName' => $recipient->getFirstname(),
                'role' => $role,
                'periodName' => $period->getName(),
                'studentName' => $student?->getDisplayName() ?? $student?->getUsername() ?? '',
                'programName' => $tutorLink->getProgram()->getDisplayShortName(),
                'ctaRoute' => $ctaRoute,
                'ctaRouteParams' => $ctaRouteParams,
            ]));
    }
}
