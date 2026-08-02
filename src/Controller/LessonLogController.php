<?php

namespace App\Controller;

use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\LessonLogAttachmentSourceType;
use App\Form\LessonLogAttachmentType;
use App\Form\LessonLogType;
use App\Repository\LessonLogAttachmentRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Service\SeanceContentResolver;
use App\Security\Voter\LessonLogVoter;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// The "cahier de texte" for a single LessonSession - see design/validated/lesson-log-cahier-de-texte.md.
// Reachable from the timetable (both the read-only student/teacher page and the staff settings
// tab) via LessonSessionEventFormatter's logUrl. Unlike ProgramTimetableSettingsController, this
// isn't staff-only: viewing follows program visibility, editing is staff-or-the-session's-own-
// teacher (see LessonLogVoter), so access is checked per-route rather than class-wide.
class LessonLogController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string ATTACHMENT_UPLOAD_PREFIX = 'lesson-logs/';

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log', name: 'app_program_timetable_session_log', methods: ['GET', 'POST'])]
    public function show(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, SeanceContentResolver $seanceContentResolver): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::VIEW, $session);

        $canEdit = $this->isGranted(LessonLogVoter::EDIT, $session);
        $log = $lessonLogRepository->findOneBySession($session);
        $isNew = null === $log;

        if ($isNew) {
            $log = new LessonLog($session);
        }

        // Disabling the parent form cascades to every child field (core Symfony Form behavior),
        // so a viewer without edit rights still sees the same template with read-only widgets
        // instead of needing a second, parallel read-view template.
        $form = $this->createForm(LessonLogType::class, $log, ['disabled' => !$canEdit]);

        if ($canEdit) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->stampAuditFields($log, !$isNew);
                $entityManager->persist($log);
                $entityManager->flush();

                $this->addFlash('success', 'lessonLogUpdatedFlashMessage');

                return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
            }
        }

        return $this->render('program/lesson_log.html.twig', [
            'program' => $program,
            'session' => $session,
            'log' => $log,
            'form' => $form,
            'canEdit' => $canEdit,
            'attachmentForm' => $canEdit ? $this->createForm(LessonLogAttachmentType::class) : null,
            // Only offered when it exists - see design/validated/teaching-sequence-library.md's
            // "relationship to part A". Part A fully works without part C ever being built.
            'seanceInstance' => $canEdit ? $seanceContentResolver->forLessonSession($session) : null,
        ]);
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/attachments', name: 'app_program_timetable_session_log_attachments_new', methods: ['POST'])]
    public function addAttachment(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $log = $lessonLogRepository->findOneBySession($session);
        $isNew = null === $log;

        if ($isNew) {
            $log = new LessonLog($session);
        }

        $form = $this->createForm(LessonLogAttachmentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('file')->getData();
            $url = $form->get('url')->getData();
            $label = $form->get('label')->getData();

            if ((null === $file) === (null === $url)) {
                // Either both empty or both filled - exactly one source is expected.
                $this->addFlash('error', null === $file ? 'lessonLogAttachmentMissingSourceFlashMessage' : 'lessonLogAttachmentBothSourcesFlashMessage');
            } else {
                if ($isNew) {
                    $this->stampAuditFields($log, false);
                }

                $attachment = new LessonLogAttachment($log, $label);

                if (null !== $file) {
                    $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
                    $key = $fileUploadService->upload(
                        self::ATTACHMENT_UPLOAD_PREFIX,
                        sprintf('%d-%d-%s.%s', $session->getId(), time(), bin2hex(random_bytes(4)), $extension),
                        $file,
                    );
                    $attachment->setType(LessonLogAttachmentSourceType::Upload);
                    $attachment->setStorageKey($key);
                } else {
                    $attachment->setType(LessonLogAttachmentSourceType::Link);
                    $attachment->setUrl($url);
                }

                $entityManager->persist($log);
                $entityManager->persist($attachment);
                $entityManager->flush();

                $this->addFlash('success', 'lessonLogAttachmentAddedFlashMessage');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/attachments/{attachmentId}/delete', name: 'app_program_timetable_session_log_attachments_delete', methods: ['POST'])]
    public function deleteAttachment(int $id, int $sessionId, int $attachmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogAttachmentRepository $attachmentRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $attachment = $attachmentRepository->find($attachmentId) ?? throw $this->createNotFoundException();

        if ($attachment->getLessonLog()->getLessonSession()?->getId() !== $session->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('lesson_log_attachment_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (LessonLogAttachmentSourceType::Upload === $attachment->getType() && null !== $attachment->getStorageKey()) {
            $fileUploadService->delete($attachment->getStorageKey());
        }

        $entityManager->remove($attachment);
        $entityManager->flush();

        $this->addFlash('success', 'lessonLogAttachmentRemovedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }

    private function findLessonSessionOrNotFound(LessonSessionRepository $repository, Program $program, int $sessionId): LessonSession
    {
        $lessonSession = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if ($lessonSession->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $lessonSession;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
