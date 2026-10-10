<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierSubmission;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Security\Voter\TeacherDossierVoter;
use App\Service\TeacherDossier\TeacherDossierFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The only door to the bytes of a dossier intervenant — for the administration and for the
 * intervenant alike, which is why it sits outside the UFA namespace.
 *
 * A piece is somebody's identity card or bank statement, so it is **never** given the CDN's
 * permanent, unauthenticated address: every opening asks App\Security\Voter\TeacherDossierVoter
 * here, then redirects to an address signed for a few minutes
 * (App\Service\TeacherDossier\TeacherDossierFiles). Refused means 404.
 *
 * `view` is an ordinary screen framing the file, for the reason
 * templates/ufa/formation/document_view.html.twig gives: a route that redirects to a signed address
 * leaves a tab whose only way back is that same redirect.
 */
#[RequiresFeature(Feature::TeacherDossiers)]
class TeacherDossierFileController extends AbstractController
{
    public function __construct(
        private readonly TeacherDossierSubmissionRepository $submissions,
        private readonly TeacherDossierFiles $files,
    ) {
    }

    #[Route(path: '/teacher-dossiers/files/{id}', name: 'app_teacher_dossier_file_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id): Response
    {
        $submission = $this->submissionOr404($id);
        $user = $this->getUser();
        /** @var TeacherDossier $dossier */
        $dossier = $submission->getDocument()?->getDossier();

        return $this->render('teacher_dossier/file_view.html.twig', [
            'dossier' => $dossier,
            'submission' => $submission,
            // Which trail to draw: the administration came from the fiche, the intervenant from
            // « Mes dossiers administratifs ». Somebody who is both reads their own as its holder.
            'asHolder' => $user instanceof User && $dossier->isHeldBy($user),
        ]);
    }

    #[Route(path: '/teacher-dossiers/files/{id}/raw', name: 'app_teacher_dossier_file_raw', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function raw(int $id): Response
    {
        return new RedirectResponse($this->files->viewUrl($this->submissionOr404($id)) ?? throw $this->createNotFoundException());
    }

    #[Route(path: '/teacher-dossiers/files/{id}/download', name: 'app_teacher_dossier_file_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id): Response
    {
        return new RedirectResponse($this->files->downloadUrl($this->submissionOr404($id)) ?? throw $this->createNotFoundException());
    }

    /** A dépôt that carries a file, on a dossier this person may read. */
    private function submissionOr404(int $id): TeacherDossierSubmission
    {
        $submission = $this->submissions->find($id);
        $dossier = $submission?->getDocument()?->getDossier();

        if (null === $submission || null === $dossier || null === $submission->getStorageKey()
            || !$this->isGranted(TeacherDossierVoter::VIEW, $dossier)) {
            throw $this->createNotFoundException();
        }

        return $submission;
    }
}
