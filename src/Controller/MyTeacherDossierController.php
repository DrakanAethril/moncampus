<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\Feature;
use App\Form\TeacherDossierSubmissionType;
use App\Repository\TeacherDossierRepository;
use App\Security\Voter\TeacherDossierVoter;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\StagedUpload;
use App\Service\TeacherDossier\TeacherDossierDeposits;
use App\Service\TeacherDossier\TeacherDossierRefusal;
use App\Service\TeacherDossier\TeacherDossierSheets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * « Mes dossiers administratifs » — the intervenant's side of the dossiers intervenants
 * (design/validated/dossiers-intervenants.md, screens 7 and 8): the dossiers handed to them, and
 * where they deposit.
 *
 * Reached from the profile menu and from the home banner. No role is asked for: **being the
 * intervenant of a dossier is the whole right** (App\Security\Voter\TeacherDossierVoter), and
 * somebody nothing was asked of simply reads an empty list. A draft is not theirs yet, and a
 * dossier that is not theirs answers 404.
 */
#[RequiresFeature(Feature::TeacherDossiers)]
class MyTeacherDossierController extends AbstractController
{
    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly TeacherDossierSheets $sheets,
        private readonly TeacherDossierDeposits $deposits,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The list — or, with exactly one dossier, that dossier: a list of one line is a click for
     * nothing, and it is by far the common case.
     */
    #[Route(path: '/my-administrative-dossiers', name: 'app_my_teacher_dossiers', methods: ['GET'])]
    public function index(): Response
    {
        $dossiers = $this->dossiers->findReadableForTeacher($this->currentUser());

        if (1 === \count($dossiers)) {
            return $this->redirectToRoute('app_my_teacher_dossier', ['id' => $dossiers[0]->getId()]);
        }

        return $this->render('teacher_dossier/my_index.html.twig', [
            'sheets' => $this->sheets->ofAll($dossiers),
        ]);
    }

    /** One dossier. `?piece=` unfolds a piece - its consigne, its dépôt form and its versions. */
    #[Route(path: '/my-administrative-dossiers/{id}', name: 'app_my_teacher_dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $dossier = $this->ownDossierOr404($id, TeacherDossierVoter::VIEW);

        return $this->render('teacher_dossier/my_show.html.twig', [
            'dossier' => $dossier,
            'sheet' => $this->sheets->of($dossier),
            'openPiece' => QueryValue::nullableInt($request, 'piece'),
            'several' => \count($this->dossiers->findReadableForTeacher($this->currentUser())) > 1,
            'uploadForm' => $this->createForm(TeacherDossierSubmissionType::class)->createView(),
        ]);
    }

    #[Route(path: '/my-administrative-dossiers/{id}/documents/{documentId}', name: 'app_my_teacher_dossier_submit', requirements: ['id' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function submit(Request $request, int $id, int $documentId, ValidatorInterface $validator): Response
    {
        // VIEW, not SUBMIT: a closed dossier is still theirs to read, and what it answers to a
        // dépôt is a sentence (TeacherDossierDeposits refuses it), not a 404.
        $dossier = $this->ownDossierOr404($id, TeacherDossierVoter::VIEW);
        $document = $this->documentOr404($dossier, $documentId);

        try {
            if (DossierDepositType::Url === $document->getDepositType()) {
                if (!$this->isCsrfTokenValid('teacher_dossier_submission', PostValue::string($request, '_token'))) {
                    throw $this->createAccessDeniedException();
                }

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

            $this->entityManager->flush();
            $this->addFlash('success', 'teacherDossierSubmissionSavedFlashMessage');
        } catch (TeacherDossierRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }

        return $this->redirectToRoute('app_my_teacher_dossier', ['id' => $dossier->getId(), 'piece' => $document->getId()]);
    }

    /**
     * The dossier, if it is this person's own.
     *
     * The Voter alone would let a member of the administration through - they read every dossier -
     * but this is « *mes* dossiers »: somebody else's is read from the UFA screens, with their
     * actions, and answering it here would draw a second fiche that can do less.
     */
    private function ownDossierOr404(int $id, string $attribute): TeacherDossier
    {
        $dossier = $this->dossiers->find($id);

        if (null === $dossier || !$dossier->isHeldBy($this->currentUser()) || !$this->isGranted($attribute, $dossier)) {
            throw $this->createNotFoundException();
        }

        return $dossier;
    }

    private function documentOr404(TeacherDossier $dossier, int $documentId): TeacherDossierDocument
    {
        foreach ($dossier->getDocuments() as $document) {
            if ($document->getId() === $documentId) {
                return $document;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
