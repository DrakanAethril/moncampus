<?php

namespace App\Service;

use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Repository\InternshipLivretEngagementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "mise à disposition du livret" gate (27b): tutor and student sign in any order, and only
 * once both are signed does signAsCenter() accept the centre representative's own signature -
 * which is what opens the alternance's evaluation periods for AlternancePeriodStatusResolver.
 * Signing is a plain authenticated-click stamp everywhere (no checkbox/code/handwritten capture -
 * see the feature's plan doc, decision #1).
 */
class AlternanceEngagementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function findOrCreate(InternshipTutorLink $tutorLink): InternshipLivretEngagement
    {
        $engagement = $this->engagementRepository->findOneForTutorLink($tutorLink);

        if (null === $engagement) {
            $engagement = new InternshipLivretEngagement($tutorLink);
            $engagement->setCreatedBy($tutorLink->getCreatedBy());
            $this->entityManager->persist($engagement);
            $this->entityManager->flush();
        }

        return $engagement;
    }

    public function signAsTutor(InternshipLivretEngagement $engagement, User $tutor): void
    {
        $engagement->setSignedTutorAt(new \DateTimeImmutable());
        $engagement->setSignedTutorBy($tutor);
        $this->entityManager->flush();
    }

    public function signAsStudent(InternshipLivretEngagement $engagement, User $student): void
    {
        $engagement->setSignedStudentAt(new \DateTimeImmutable());
        $engagement->setSignedStudentBy($student);
        $this->entityManager->flush();
    }

    // Throws (rather than silently no-op) if the tutor/student signatures aren't both in yet, so
    // the controller can turn this into a flash message - the "n'est proposée qu'ensuite" rule
    // from the spec is enforced here, not just by hiding the button in the template.
    public function signAsCenter(InternshipLivretEngagement $engagement, User $staff): void
    {
        if (null === $engagement->getSignedTutorAt() || null === $engagement->getSignedStudentAt()) {
            throw new \DomainException('Cannot sign as centre representative before both the tutor and the student have signed.');
        }

        $engagement->setSignedCenterAt(new \DateTimeImmutable());
        $engagement->setSignedCenterBy($staff);
        $this->entityManager->flush();
    }

    // Called once, right after the alternance is created (UfaAlternanceController::
    // createAlternance()) - invites the tutor and the student to sign their own part of the
    // engagement. Not a "reminder" (no InternshipReminder row logged here, see
    // AlternanceReminderService) - this is the initial invite, chasing a still-missing signature
    // afterward is what the reminder flow is for.
    public function sendEngagementInvites(InternshipTutorLink $tutorLink): void
    {
        $tutorEmail = $tutorLink->getTutor()?->getEmail() ?? $tutorLink->getTutorEmail();
        $this->mailer->send((new TemplatedEmail())
            ->to($tutorEmail)
            ->subject($this->translator->trans('ufaAlternanceEngagementInviteEmailSubject'))
            ->htmlTemplate('emails/internship_alternance_engagement_invite.html.twig')
            ->context([
                'recipientFirstName' => $tutorLink->getTutor()?->getFirstname() ?? $tutorLink->getTutorFirstName(),
                'role' => 'tutor',
            ]));

        $student = $tutorLink->getStudent();
        if (null !== $student?->getEmail()) {
            $this->mailer->send((new TemplatedEmail())
                ->to($student->getEmail())
                ->subject($this->translator->trans('ufaAlternanceEngagementInviteEmailSubject'))
                ->htmlTemplate('emails/internship_alternance_engagement_invite.html.twig')
                ->context([
                    'recipientFirstName' => $student->getFirstname(),
                    'role' => 'student',
                ]));
        }
    }
}
