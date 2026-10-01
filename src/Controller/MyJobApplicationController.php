<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\JobApplicationRepository;
use App\Repository\JobSearchRepository;
use App\Service\CompanySearch\CompanyLeads;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\JobApplicationSummaryBuilder;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My job search" - the student's view of their own applications
 * (design_handoff_stage_alternance, screen 2b).
 *
 * The mockup is deliberately bare, and the README states it as a constraint: no banner, no "to do"
 * or follow-up block, no goals, no right-hand column, no "Declare an application". A full-width
 * list, grouped by company, with purely factual rows. Do not add guidance here: that lives on the
 * teacher side (screen 2a), not on this screen.
 *
 * **Gated on `school_mail`, not on `job_search`**, and that is not a slip: an application *is* a
 * mail sent from the student's school mailbox, this screen is the list of those mails grouped by
 * company, and the mailbox links straight to it (templates/school_mail/_folders.html.twig). With
 * the mailbox closed - by the role matrix or by the student's formation, `school_mail` carrying
 * both axes - there is nothing to list and no way to add to it. The teachers' side of the job
 * search (« Démarches », the tracking screen) keeps `job_search`: it reads what a class did, and
 * that reading is a different decision from opening a mailbox.
 */
#[RequiresFeature(Feature::SchoolMail)]
class MyJobApplicationController extends AbstractController
{
    /**
     * The mockup's filters, plus « À écrire » - a company kept from « Trouver une entreprise »
     * with nothing sent yet (design/validated/vivier-entreprises.md §6). "Awaiting reply" = sent,
     * no reply received, not a verdict on the outcome.
     */
    private const array FILTERS = ['to_write', 'all', 'pending', 'answered'];

    /**
     * The mockup only shows the first few applications and puts the rest behind "show all". The
     * screen then says how many it is holding back: it never truncates silently.
     */
    private const int VISIBLE_ROWS = 4;

    /** The mockup's five colour dots, handed out per company rather than per state. */
    private const int ACCENTS = 5;

    #[Route(path: '/my/applications', name: 'app_my_job_applications', methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function __invoke(
        Request $request,
        JobApplicationRepository $applicationRepository,
        JobSearchRepository $searchRepository,
        JobApplicationSummaryBuilder $summaryBuilder,
        SchoolLocation $school,
    ): Response {
        /** @var User $student */
        $student = $this->getUser();

        $filter = (string) $request->query->get('filter', 'all');

        if (!\in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $showAll = $request->query->getBoolean('all');
        $rows = [];

        foreach ($applicationRepository->findForStudent($student) as $application) {
            $summary = $summaryBuilder->summarize($application);

            if (!$this->matchesFilter($application, $summary, $filter)) {
                continue;
            }

            $rows[] = [
                'application' => $application,
                'summary' => $summary,
                // The colour follows the démarche, not its state: a visual landmark stable from one
                // screen to the next, not one more piece of information.
                'accent' => $application->getId() % self::ACCENTS,
                'latestReply' => false,
            ];
        }

        // Like the teacher's sheet (2a): the application that moved last comes first.
        usort($rows, static fn (array $left, array $right): int => ($right['summary']['lastActivityAt'] <=> $left['summary']['lastActivityAt']));

        $latestReply = $this->latestReplyIndex($rows);
        if (null !== $latestReply) {
            $rows[$latestReply]['latestReply'] = true;
        }

        $total = \count($rows);

        return $this->render('job_application/my_applications.html.twig', [
            'rows' => $showAll ? $rows : \array_slice($rows, 0, self::VISIBLE_ROWS),
            'total' => $total,
            'hiddenCount' => $showAll ? 0 : max(0, $total - self::VISIBLE_ROWS),
            'filter' => $filter,
            'filters' => self::FILTERS,
            // A closed job search leaves the mailbox readable but turns sending off (screen 1a),
            // so the screen must say it rather than hide anything.
            'searchClosed' => $searchRepository->isClosedFor($student),
            'toWriteCount' => \count(array_filter($applicationRepository->findForStudent($student), static fn (JobApplication $application): bool => $application->isToWrite())),
            'schoolDepartment' => $school->department(),
        ]);
    }

    /**
     * The row the mockup highlights is the last news *received*, not the last row touched: a
     * follow-up you have just sent yourself is news to nobody.
     *
     * Answers the index rather than stamping the row itself: writing back through a by-reference
     * parameter widened the row shape for every later reader, for one boolean the caller can set.
     *
     * @param list<array{summary: array{replyAt: ?\DateTimeImmutable}, latestReply: bool}> $rows
     *
     * @return int|null index of the row to highlight, null if nothing has been answered yet
     */
    private function latestReplyIndex(array $rows): ?int
    {
        $latest = null;

        foreach ($rows as $index => $row) {
            $replyAt = $row['summary']['replyAt'];

            if (null !== $replyAt && (null === $latest || $replyAt > $rows[$latest]['summary']['replyAt'])) {
                $latest = $index;
            }
        }

        return $latest;
    }

    /**
     * The student's note on one of their démarches - read by their teachers, and the field says so.
     */
    #[Route(path: '/my/applications/{id}/note', name: 'app_my_job_application_note', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function note(int $id, Request $request, JobApplicationRepository $applicationRepository, EntityManagerInterface $entityManager): Response
    {
        $application = $this->ownApplication($id, $applicationRepository);
        if (!$this->isCsrfTokenValid('job_application_note', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $application->setStudentNote(mb_substr((string) $request->request->get('note', ''), 0, 2000));
        $entityManager->flush();

        return $this->redirectToRoute('app_my_job_applications', ['filter' => QueryValue::string($request, 'filter', 'all'), '_fragment' => 'application-'.$id]);
    }

    /** « Retirer » - only a démarche nothing was sent or received in: past that, it is a trace. */
    #[Route(path: '/my/applications/{id}/remove', name: 'app_my_job_application_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function remove(int $id, Request $request, JobApplicationRepository $applicationRepository, CompanyLeads $leads): Response
    {
        $application = $this->ownApplication($id, $applicationRepository);
        if (!$this->isCsrfTokenValid('job_application_remove', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $student */
        $student = $this->getUser();
        $this->addFlash($leads->remove($student, $application) ? 'success' : 'warning', $application->isToWrite() ? 'companyLeadRemovedFlash' : 'companyLeadNotRemovableFlash');

        return $this->redirectToRoute('app_my_job_applications', ['filter' => 'to_write']);
    }

    private function ownApplication(int $id, JobApplicationRepository $applicationRepository): JobApplication
    {
        $application = $applicationRepository->find($id);
        if (null === $application || $application->getStudent() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $application;
    }

    /** @param array{replyAt: ?\DateTimeImmutable} $summary */
    private function matchesFilter(JobApplication $application, array $summary, string $filter): bool
    {
        return match ($filter) {
            'to_write' => $application->isToWrite(),
            'pending' => !$application->isToWrite() && null === $summary['replyAt'],
            'answered' => null !== $summary['replyAt'],
            default => true,
        };
    }
}
