<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierGroup;
use App\Enum\DossierDepositType;
use App\Enum\DossierValidationProfile;
use App\Enum\Feature;
use App\Repository\TeacherDossierRepository;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The editor of a dossier intervenant's pieces — and, unchanged, of a model's
 * (design/validated/dossiers-intervenants.md, screen 4).
 *
 * Three steps, three routes, as on the dossiers documentaires: Paramètres (title, consignes),
 * Pièces (groups, pieces, the property panel), Récapitulatif. A model has no third step - there is
 * nobody to publish it to - and its pieces carry no date.
 *
 * It stays open **after publication**: a piece is added (the dossier reads incomplete again and the
 * banner comes back) or corrected. The one refusal is deleting a piece somebody has already
 * deposited on - that would throw their file away with it; such a piece is made facultative.
 */
#[IsGranted(new Expression(self::ADMINISTRATION_EXPRESSION))]
#[RequiresFeature(Feature::TeacherDossiers)]
class TeacherDossierCompositionController extends AbstractController
{
    use TeacherDossierControllerTrait;

    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly EntityManagerInterface $entityManager,
        private readonly TeacherDossierSubmissionRepository $submissions,
    ) {
    }

    #[Route(path: '/ufa/teacher-dossiers/{id}/settings', name: 'app_ufa_teacher_dossier_settings', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(Request $request, int $id, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id);

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $dossier
                ->setTitle(PostValue::trimmed($request, 'title'))
                ->setDescription(PostValue::trimmed($request, 'description') ?: null);

            if (0 === \count($validator->validate($dossier))) {
                $this->stamp($dossier);
                $this->entityManager->flush();

                return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId()]);
            }

            $this->addFlash('error', 'teacherDossierSettingsInvalidFlashMessage');

            // 422: Turbo only redraws a POST's answer when it is a redirect or an error status.
            return $this->renderEditor($dossier, 1, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderEditor($dossier, 1);
    }

    /**
     * Step 2. `?piece=` selects the row loaded in the property panel, `?piece=new` opens it empty:
     * a query parameter, so the panel survives the save that follows and « + Pièce » is a link.
     */
    #[Route(path: '/ufa/teacher-dossiers/{id}/documents', name: 'app_ufa_teacher_dossier_documents', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documents(Request $request, int $id): Response
    {
        return $this->renderEditor($this->dossierOr404($id), 2, QueryValue::trimmed($request, 'piece'));
    }

    #[Route(path: '/ufa/teacher-dossiers/{id}/documents/save', name: 'app_ufa_teacher_dossier_document_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveDocument(Request $request, int $id, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);

        $documentId = PostValue::nullableInt($request, 'document_id');
        $document = null === $documentId ? new TeacherDossierDocument($dossier) : $this->documentOr404($dossier, $documentId);
        $groupId = PostValue::nullableInt($request, 'group_id');

        $document
            ->setName(PostValue::trimmed($request, 'name'))
            ->setGroup(null === $groupId ? null : $this->groupOr404($dossier, $groupId))
            ->setRequired(PostValue::bool($request, 'required'))
            ->setDepositType(DossierDepositType::tryFrom(PostValue::string($request, 'deposit_type')) ?? DossierDepositType::Upload)
            ->setValidationProfile(DossierValidationProfile::tryFrom(PostValue::string($request, 'validation_profile')) ?? DossierValidationProfile::Deposit)
            ->setDueOn($this->date(PostValue::string($request, 'due_on')));

        if (\count($validator->validate($document)) > 0) {
            // Nothing is flushed on this path, so the piece built above simply never reaches the
            // database.
            $this->addFlash('error', 'teacherDossierDocumentInvalidFlashMessage');

            return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId(), 'piece' => $documentId ?? 'new']);
        }

        $this->entityManager->persist($document);
        $this->stamp($dossier);
        $this->entityManager->flush();

        // Adding pieces is a gesture somebody repeats: the panel goes back to « Nouvelle pièce ».
        // Correcting one keeps it selected - there the panel is the only place the change reads.
        return $this->redirectToRoute('app_ufa_teacher_dossier_documents', [
            'id' => $dossier->getId(),
            'piece' => null === $documentId ? 'new' : $document->getId(),
        ]);
    }

    #[Route(path: '/ufa/teacher-dossiers/{id}/documents/{documentId}/delete', name: 'app_ufa_teacher_dossier_document_delete', requirements: ['id' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function deleteDocument(Request $request, int $id, int $documentId): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);
        $document = $this->documentOr404($dossier, $documentId);

        if ($this->submissions->hasAny($document)) {
            $this->addFlash('error', 'teacherDossierDocumentHasSubmissionFlashMessage');

            return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId(), 'piece' => $documentId]);
        }

        $dossier->removeDocument($document);
        $this->entityManager->remove($document);
        $this->stamp($dossier);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId()]);
    }

    /** « + Groupe » — a title and a place at the end of the list, nothing more. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/groups', name: 'app_ufa_teacher_dossier_group_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createGroup(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);
        $title = mb_substr(PostValue::trimmed($request, 'title'), 0, 255);

        if ('' !== $title) {
            $this->entityManager->persist(new TeacherDossierGroup($dossier, $title, $dossier->nextGroupPosition()));
            $this->stamp($dossier);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId()]);
    }

    /** Step 3 — what the dossier asks, read back before it is handed to its intervenant. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/summary', name: 'app_ufa_teacher_dossier_summary', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function summary(int $id): Response
    {
        $dossier = $this->dossierOr404($id);

        if ($dossier->isTemplate()) {
            return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId()]);
        }

        return $this->renderEditor($dossier, 3);
    }

    // ---------------------------------------------------------------------------------------

    private function renderEditor(TeacherDossier $dossier, int $step, string $selection = '', int $status = Response::HTTP_OK): Response
    {
        $selected = $this->selectedDocument($dossier, $selection);

        return $this->render('ufa/teacher_dossier/editor.html.twig', [
            'dossier' => $dossier,
            'step' => $step,
            'groups' => $dossier->groupedDocuments(),
            'selection' => $selected,
            'isNewDocument' => 'new' === $selection,
            // Drawn on the selected piece only: whether its bin is offered at all.
            'selectionLocked' => null !== $selected && $this->submissions->hasAny($selected),
            'depositTypes' => DossierDepositType::cases(),
            'validationProfiles' => DossierValidationProfile::cases(),
        ], new Response(status: $status));
    }

    /**
     * What the property panel edits: the named piece, a blank one, or - failing both - the first of
     * the list, so the panel is never empty on a dossier that has pieces.
     */
    private function selectedDocument(TeacherDossier $dossier, string $selection): ?TeacherDossierDocument
    {
        if ('new' === $selection) {
            return null;
        }

        if (ctype_digit($selection)) {
            foreach ($dossier->getDocuments() as $document) {
                if ((string) $document->getId() === $selection) {
                    return $document;
                }
            }
        }

        return $dossier->getDocuments()->first() ?: null;
    }

    private function groupOr404(TeacherDossier $dossier, int $groupId): TeacherDossierGroup
    {
        foreach ($dossier->getGroups() as $group) {
            if ($group->getId() === $groupId) {
                return $group;
            }
        }

        throw $this->createNotFoundException();
    }
}
