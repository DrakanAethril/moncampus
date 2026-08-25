<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\EmailMessage;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\PlatformActivityType;
use App\Repository\EmailMessageRepository;
use App\Repository\JobApplicationRepository;
use App\Repository\UserRepository;
use App\Service\PlatformActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mails non rattachés" - the manual review queue (design_handoff_stage_alternance, screen 5a).
 *
 * SES reception is catch-all, so the whole domain arrives: what matches neither a known Message-ID
 * nor an existing student address lands here. A typo, a mail to a leaver, a service address, spam.
 *
 * Two actions, and their asymmetry is the point:
 * - "Rattacher" gives the mail an owner, and an application when one is obvious - after which it
 *   simply shows up in that student's mailbox, as if it had been resolved on arrival;
 * - "Supprimer" removes it from the platform only. The raw `.eml` stays archived on S3, which is the
 *   source of truth, and the deletion is recorded in the platform log - an admin erasing a student's
 *   incoming mail is exactly the kind of act that has to leave a trace.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::SchoolMailSupervision)]
class UnlinkedSchoolMailController extends AbstractController
{
    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly JobApplicationRepository $applicationRepository,
        private readonly UserRepository $userRepository,
        private readonly PlatformActivityRecorder $activityRecorder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/admin/school-mail/unlinked', name: 'app_school_mail_unlinked', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('school_mail/unlinked.html.twig', [
            'messages' => $this->messageRepository->findUnlinked(),
        ]);
    }

    /** Students matching what was typed, for the picker of the "Rattacher" form. */
    #[Route(path: '/admin/school-mail/unlinked/students', name: 'app_school_mail_unlinked_students', methods: ['GET'])]
    public function students(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));

        if (mb_strlen($term) < 2) {
            return $this->json([]);
        }

        $students = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->andWhere('u.inactiveDate IS NULL')
            ->andWhere('u.firstname LIKE :term OR u.lastname LIKE :term OR u.username LIKE :term')
            ->setParameter('role', '%ROLE_STUDENT%')
            ->setParameter('term', '%'.$term.'%')
            ->orderBy('u.lastname', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        // The response shape Tom Select is wired for across the app: {results, pagination}.
        return $this->json([
            'results' => array_map(static fn (User $student): array => [
                'id' => $student->getId(),
                'text' => trim(($student->getFirstname() ?? '').' '.($student->getLastname() ?? '')).' ('.$student->getUsername().')',
            ], $students),
            'pagination' => ['more' => false],
        ]);
    }

    #[Route(path: '/admin/school-mail/unlinked/{id}/link', name: 'app_school_mail_unlinked_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function link(Request $request, EmailMessage $message): Response
    {
        $this->denyUnlessUnlinked($message);

        if (!$this->isCsrfTokenValid('school_mail_unlinked_link', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $student = $this->userRepository->find((int) $request->request->get('student'));

        if (null === $student) {
            $this->addFlash('danger', 'schoolMailUnlinkedStudentRequiredError');

            return $this->redirectToRoute('app_school_mail_unlinked');
        }

        $message->setStudent($student);

        $applicationId = (int) $request->request->get('application');

        if ($applicationId > 0) {
            $application = $this->applicationRepository->find($applicationId);

            // An application belonging to somebody else would put this mail in two students' hands
            // at once - the owner we just set, and the one the application belongs to.
            if (null !== $application && $application->getStudent()?->getId() === $student->getId()) {
                $message->setJobApplication($application);
            }
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'schoolMailUnlinkedLinkedFlash');

        return $this->redirectToRoute('app_school_mail_unlinked');
    }

    /** The applications of one student, to offer them once a student has been picked. */
    #[Route(path: '/admin/school-mail/unlinked/applications/{studentId}', name: 'app_school_mail_unlinked_applications', requirements: ['studentId' => '\d+'], methods: ['GET'])]
    public function applications(int $studentId): JsonResponse
    {
        $student = $this->userRepository->find($studentId);

        if (null === $student) {
            return $this->json([]);
        }

        return $this->json(array_map(static fn ($application): array => [
            'id' => $application->getId(),
            'text' => $application->getName(),
        ], $this->applicationRepository->findForStudent($student)));
    }

    #[Route(path: '/admin/school-mail/unlinked/{id}/delete', name: 'app_school_mail_unlinked_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, EmailMessage $message): Response
    {
        $this->denyUnlessUnlinked($message);

        if (!$this->isCsrfTokenValid('school_mail_unlinked_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $admin */
        $admin = $this->getUser();

        // Logged before the row is gone, and carrying the S3 keys: they are what makes the deleted
        // mail findable again in the bucket, which still holds it.
        $this->activityRecorder->record(PlatformActivityType::SchoolMailUnlinkedDeleted, $admin, $request, [
            'messageId' => $message->getMessageId(),
            's3Key' => $message->getS3Key(),
            'sourceKey' => $message->getSourceKey(),
            'from' => $message->getFromAddress(),
            'recipientLocalPart' => $message->getRecipientLocalPart(),
            'subject' => $message->getSubject(),
        ]);

        $this->entityManager->remove($message);
        $this->entityManager->flush();
        $this->addFlash('success', 'schoolMailUnlinkedDeletedFlash');

        return $this->redirectToRoute('app_school_mail_unlinked');
    }

    /** Acting on a mail that has since been resolved would silently undo somebody else's work. */
    private function denyUnlessUnlinked(EmailMessage $message): void
    {
        if (null !== $message->getStudent()) {
            throw $this->createNotFoundException();
        }
    }
}
