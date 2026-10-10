<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Enum\DossierDepositType;
use App\Enum\DossierReviewAction;
use App\Enum\Feature;
use App\Form\TeacherDossierSubmissionType;
use App\Repository\TeacherDossierRepository;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Security\Voter\TeacherDossierVoter;
use App\Service\PostValue;
use App\Service\StagedUpload;
use App\Service\TeacherDossier\TeacherDossierDeposits;
use App\Service\TeacherDossier\TeacherDossierRefusal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The two things the administration writes on a piece of somebody's dossier: its answer to a dépôt,
 * and a dépôt made **for** the intervenant (design/validated/dossiers-intervenants.md, screen 5).
 *
 * Thin: whether the piece takes it and what is written are
 * App\Service\TeacherDossier\TeacherDossierDeposits', the door the intervenant's own screen goes
 * through as well. Both routes come back on the fiche with the same piece unfolded.
 */
#[IsGranted(new Expression(self::ADMINISTRATION_EXPRESSION))]
#[RequiresFeature(Feature::TeacherDossiers)]
class TeacherDossierReviewController extends AbstractController
{
    use TeacherDossierControllerTrait;

    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly EntityManagerInterface $entityManager,
        private readonly TeacherDossierDeposits $deposits,
    ) {
    }

    /** « Valider » or « Demander une correction » - never on one's own dossier (the Voter refuses). */
    #[Route(path: '/ufa/teacher-dossiers/{id}/submissions/{submissionId}/review', name: 'app_ufa_teacher_dossier_review', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['POST'])]
    public function review(Request $request, int $id, int $submissionId, TeacherDossierSubmissionRepository $submissions): Response
    {
        $dossier = $this->dossierOr404($id, TeacherDossierVoter::REVIEW);
        $this->assertToken($request);

        $submission = $submissions->find($submissionId);
        $document = $submission?->getDocument();

        if (null === $submission || null === $document || $document->getDossier() !== $dossier) {
            throw $this->createNotFoundException();
        }

        $action = DossierReviewAction::tryFrom(PostValue::string($request, 'action')) ?? throw $this->createNotFoundException();

        try {
            $this->deposits->review($submission, $this->currentUser(), $action, PostValue::string($request, 'comment'));
            $this->stamp($dossier);
            $this->entityManager->flush();
            $this->addFlash('success', DossierReviewAction::Validated === $action ? 'teacherDossierValidatedFlashMessage' : 'teacherDossierCorrectionRequestedFlashMessage');
        } catch (TeacherDossierRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }

        return $this->back($dossier, $document);
    }

    /** « Déposer pour l'intervenant » — a piece that reached the administration on paper or by mail. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/documents/{documentId}/deposit', name: 'app_ufa_teacher_dossier_deposit', requirements: ['id' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function deposit(Request $request, int $id, int $documentId, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id, TeacherDossierVoter::SUBMIT);
        $document = $this->documentOr404($dossier, $documentId);

        try {
            if (DossierDepositType::Url === $document->getDepositType()) {
                $this->assertToken($request);
                $url = PostValue::trimmed($request, 'url');

                if ('' === $url || mb_strlen($url) > 2048 || \count($validator->validate($url, new Url(requireTld: true))) > 0) {
                    throw new TeacherDossierRefusal('teacherDossierSubmissionInvalidUrlFlashMessage');
                }

                $this->deposits->depositUrl($document, $this->currentUser(), $url);
            } else {
                $form = $this->createForm(TeacherDossierSubmissionType::class);
                $form->handleRequest($request);
                $file = $form->isSubmitted() && $form->isValid() ? $form->get('file')->getData() : null;

                if (!$file instanceof StagedUpload) {
                    throw new TeacherDossierRefusal('teacherDossierSubmissionInvalidFileFlashMessage');
                }

                $this->deposits->depositFile($document, $this->currentUser(), $file);
            }

            $this->stamp($dossier);
            $this->entityManager->flush();
            $this->addFlash('success', 'teacherDossierSubmissionSavedFlashMessage');
        } catch (TeacherDossierRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }

        return $this->back($dossier, $document);
    }

    private function back(TeacherDossier $dossier, TeacherDossierDocument $document): Response
    {
        return $this->redirectToRoute('app_ufa_teacher_dossier_show', ['id' => $dossier->getId(), 'piece' => $document->getId()]);
    }
}
