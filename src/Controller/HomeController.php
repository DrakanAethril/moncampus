<?php

namespace App\Controller;

use App\Entity\AgendaEvent;
use App\Entity\Announcement;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\AgendaEventRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\LaptopLoanRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\SequenceTemplateRepository;
use App\Repository\TicketRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\AudienceTargetableVoter;
use App\Service\TicketStatusFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Post-login dashboard - composed of role-gated sections (student/teacher/staff) built here so
 * the role logic lives in one place; the template only checks whether a given key was set.
 */
class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        ProgramRepository $programRepository,
        LessonSessionRepository $lessonSessionRepository,
        SequenceTemplateRepository $sequenceTemplateRepository,
        TicketRepository $ticketRepository,
        LaptopLoanRepository $laptopLoanRepository,
        StructureAccessChecker $structureAccessChecker,
        TicketStatusFormatter $ticketStatusFormatter,
        AnnouncementRepository $announcementRepository,
        AgendaEventRepository $agendaEventRepository,
    ): Response {
        $user = $this->currentUser();

        // ROLE_EXTERNAL (entreprise tutors) have no use for the staff/student navigation this
        // page's layout is built around - route them straight to their own landing instead.
        if (\in_array('ROLE_EXTERNAL', $user->getRoles(), true)) {
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        $today = new \DateTimeImmutable('today');
        $weekFromNow = $today->modify('+7 days');
        $widgetLimit = 3;
        $viewData = [
            'user' => $user,
            // Same audience/visibility check as AnnouncementController/AgendaController's own
            // lists - narrowed here to a short "recent"/"next" digest for the dashboard widgets
            // (templates/home/_announcements_widget.html.twig, _agenda_widget.html.twig).
            'announcements' => \array_slice(array_values(array_filter(
                $announcementRepository->findAllActive(),
                fn (Announcement $announcement): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $announcement),
            )), 0, $widgetLimit),
            'agendaEvents' => \array_slice(array_values(array_filter(
                $agendaEventRepository->findUpcoming(),
                fn (AgendaEvent $event): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $event),
            )), 0, $widgetLimit),
        ];

        if ($this->isGranted('ROLE_STUDENT')) {
            $program = $programRepository->findActiveForStudent($user);
            $viewData['student'] = [
                'program' => $program,
                'sessions' => null !== $program ? $lessonSessionRepository->findForProgramBetween($program, $today, $weekFromNow) : [],
                'openTicketCount' => $ticketRepository->countForReporter($user),
            ];
        }

        if ($this->isGranted('ROLE_TEACHER')) {
            $sessionsWithoutLog = $lessonSessionRepository->findRecentWithoutLogForTeacher($user, $today->modify('-14 days'), $today);
            $viewData['teacher'] = [
                'programs' => $programRepository->findAllForTeacher($user),
                'upcomingSessions' => $lessonSessionRepository->findUpcomingForTeacher($user, $today, $weekFromNow),
                'sessionsWithoutLog' => $sessionsWithoutLog,
                'sessionsWithoutLogCount' => \count($sessionsWithoutLog),
                'sequenceCount' => \count($sequenceTemplateRepository->findForTeacher($user)),
                'openTicketCount' => $ticketRepository->countForReporter($user),
            ];
        }

        if ($structureAccessChecker->isStaff()) {
            $recentOpenTickets = $ticketRepository->findPage(0, 5, status: Ticket::STATUS_OPEN);
            $viewData['staff'] = [
                'openTicketCount' => $ticketRepository->countAll(status: Ticket::STATUS_OPEN),
                'assignedTicketCount' => $ticketRepository->countAll(status: Ticket::STATUS_OPEN, assigneeId: $user->getId()),
                'recentOpenTickets' => array_map(
                    static fn (Ticket $ticket): array => [
                        'ticket' => $ticket,
                        'statusLabel' => $ticketStatusFormatter->statusLabel($ticket->getStatus()),
                        'statusClass' => $ticketStatusFormatter->statusCssClass($ticket->getStatus()),
                        'priorityLabel' => $ticketStatusFormatter->priorityLabel($ticket->getPriority()),
                        'priorityClass' => $ticketStatusFormatter->priorityCssClass($ticket->getPriority()),
                    ],
                    $recentOpenTickets,
                ),
                'activeLoanCount' => $laptopLoanRepository->countAll(onlyActive: true),
            ];
        }

        return $this->render('home/index.html.twig', $viewData);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
