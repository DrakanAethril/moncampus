<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Service\PostValue;
use App\Service\QueryValue;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les relances d'évaluation envoyées depuis la formation.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipReminderController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/ufa/programs/{id}/tutors/reminders', name: 'app_ufa_formation_tutors_reminders')]
    public function evaluationReminders(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        // The guard used to be `null !== ...get('period')`, which lets the empty string through to
        // getInt() and turns the blank option of the period filter into a 400. nullableInt says
        // "absent, blank or unreadable" in one reading.
        $periodId = QueryValue::nullableInt($request, 'period');
        $period = null !== $periodId ? $evaluationPeriodRepository->find($periodId) : null;
        $pending = null !== $period ? $this->findPendingEvaluations($program, $period, $studentEvaluationRepository, $tutorEvaluationRepository, $tutorLinkRepository) : ['students' => [], 'tutorLinks' => []];

        return $this->render('program/internship_evaluation_reminders.html.twig', [
            'program' => $program,
            'periods' => $evaluationPeriodRepository->findAllActiveForProgram($program),
            'selectedPeriod' => $period,
            'pendingStudents' => $pending['students'],
            'pendingTutorLinks' => $pending['tutorLinks'],
        ]);
    }

    #[Route(path: '/ufa/programs/{id}/tutors/reminders/send', name: 'app_ufa_formation_tutors_reminders_send', methods: ['POST'])]
    public function sendEvaluationReminders(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $period = $evaluationPeriodRepository->find(PostValue::int($request, 'period')) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('program_internship_reminders_send', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $pending = $this->findPendingEvaluations($program, $period, $studentEvaluationRepository, $tutorEvaluationRepository, $tutorLinkRepository);

        // User::$contactEmail only, here as everywhere else - anyone without one is skipped
        // silently and left out of the count, so the flash message reports what actually went out.
        $sent = 0;

        $studentSubject = $translator->trans('internshipStudentEvaluationReminderEmailSubject');
        foreach ($pending['students'] as $student) {
            if (null === $student->getContactEmail()) {
                continue;
            }

            $mailer->send((new TemplatedEmail())
                ->to($student->getContactEmail())
                ->subject($studentSubject)
                ->htmlTemplate('emails/internship_student_evaluation_reminder.html.twig')
                ->context(['program' => $program, 'period' => $period, 'student' => $student]));
            ++$sent;
        }

        $tutorSubject = $translator->trans('internshipTutorEvaluationReminderEmailSubject');
        foreach ($pending['tutorLinks'] as $tutorLink) {
            $tutorEmail = $tutorLink->getTutor()?->getContactEmail();
            if (null === $tutorEmail) {
                continue;
            }

            $mailer->send((new TemplatedEmail())
                ->to($tutorEmail)
                ->subject($tutorSubject)
                ->htmlTemplate('emails/internship_tutor_evaluation_reminder.html.twig')
                ->context(['program' => $program, 'period' => $period, 'tutorLink' => $tutorLink]));
            ++$sent;
        }

        $this->addFlash('success', $translator->trans('internshipEvaluationRemindersSentFlashMessage', [
            '%count%' => $sent,
        ]));

        return $this->redirectToRoute('app_ufa_formation_tutors_reminders', ['id' => $program->getId(), 'period' => $period->getId()]);
    }

    /** @return array{students: list<User>, tutorLinks: list<InternshipTutorLink>} */
    private function findPendingEvaluations(Program $program, InternshipEvaluationPeriod $evaluationPeriod, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository): array
    {
        $submittedStudentIds = $studentEvaluationRepository->findSubmittedStudentIdsForProgramAndEvaluationPeriod($program, $evaluationPeriod);
        $pendingStudents = array_values(array_filter(
            $program->getStudents()->toArray(),
            static fn (User $student): bool => !\in_array($student->getId(), $submittedStudentIds, true),
        ));

        $submittedTutorLinkIds = $tutorEvaluationRepository->findSubmittedTutorLinkIdsForProgramAndEvaluationPeriod($program, $evaluationPeriod);
        $pendingTutorLinks = array_values(array_filter(
            $tutorLinkRepository->findAllActiveForProgram($program),
            static fn (InternshipTutorLink $tutorLink): bool => !\in_array($tutorLink->getId(), $submittedTutorLinkIds, true),
        ));

        return ['students' => $pendingStudents, 'tutorLinks' => $pendingTutorLinks];
    }
}
