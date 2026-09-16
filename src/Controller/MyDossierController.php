<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\Feature;
use App\Form\DossierSubmissionType;
use App\Repository\DossierRepository;
use App\Repository\DossierSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierBoardBuilder;
use App\Service\Dossier\DossierStatusResolver;
use App\Service\Dossier\DossierTargetResolver;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * « Mes dossiers » — the cible's side.
 *
 * The screen a student reads is the same board as the Suivi, built for one person
 * (App\Service\Dossier\DossierBoardBuilder::buildForStudent()), so their avancement and a
 * validateur's reading of it cannot differ.
 *
 * Two rules the routes carry rather than the template:
 *
 * - **a document that is not visible yet does not exist**: it is drawn, greyed, with no action, and
 *   the POST below refuses it. Drawing it and refusing it are the same decision read twice.
 * - **a dépôt is a new version, never an overwrite.** Redepositing after a correction writes v2 and
 *   leaves v1 where it is, with the comment that asked for it - that is the whole of « Mes dépôts ».
 */
#[RequiresFeature(Feature::Dossiers)]
class MyDossierController extends AbstractController
{
    private const string SUBMISSION_UPLOAD_PREFIX = 'dossier-submissions/';

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierBoardBuilder $boards,
        private readonly DossierStatusResolver $statuses,
        private readonly DossierSubmissionRepository $submissions,
        private readonly DossierTargetResolver $targets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/my-dossiers', name: 'app_my_dossiers', methods: ['GET'])]
    public function index(ProgramRepository $programs): Response
    {
        $student = $this->currentUser();
        $className = $programs->findActiveForStudent($student)?->getDisplayShortName() ?? '';

        $rows = [];

        foreach ($this->dossiers->findPublishedForStudent($student) as $dossier) {
            // The query answers « a dossier naming your class »; this takes back out the ones
            // narrowed to an option this student does not carry. One rule, read from one place -
            // the same one the Voter asks before letting them deposit.
            if (!$this->targets->isTarget($dossier, $student)) {
                continue;
            }

            $rows[] = ['dossier' => $dossier, 'row' => $this->boards->buildForStudent($dossier, $student, $className)];
        }

        return $this->render('dossier/my_index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/my-dossiers/{id}', name: 'app_my_dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id, ProgramRepository $programs): Response
    {
        $dossier = $this->dossierOr404($id);
        $student = $this->currentUser();
        $className = $programs->findActiveForStudent($student)?->getDisplayShortName() ?? '';
        $row = $this->boards->buildForStudent($dossier, $student, $className);

        $open = QueryValue::nullableInt($request, 'document');

        return $this->render('dossier/my_show.html.twig', [
            'dossier' => $dossier,
            'row' => $row,
            'groups' => $this->groupedCells($dossier, $row->cells),
            'openDocument' => $open,
            // The upload field of whichever document is open: the platform's one picker, so the
            // student's file is staged, typed and virus-checked while they are still on the page.
            'uploadForm' => $this->createForm(DossierSubmissionType::class)->createView(),
            // Whether the dépôt is open, per document: the panel draws the dropzone only when the
            // POST behind it would accept a file, so the two answers are one.
            'accepts' => $this->acceptance($row->cells),
            'nextDeadline' => $this->nextDeadline($row->cells),
            'pendingCount' => $this->pendingCount($row->cells),
        ]);
    }

    #[Route(path: '/my-dossiers/{id}/documents/{documentId}', name: 'app_my_dossier_submit', requirements: ['id' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function submit(Request $request, int $id, int $documentId, UploadIntake $uploads, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id);
        $student = $this->currentUser();
        $document = $this->documentOr404($dossier, $documentId);

        if (DossierDepositType::Url === $document->getDepositType()
            && !$this->isCsrfTokenValid('dossier_submission', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }

        $existing = array_values(array_filter(
            $this->submissions->findForDossierAndStudent($dossier, $student),
            static fn (DossierSubmission $submission): bool => $submission->getDocument()?->getId() === $documentId,
        ));

        if (!$this->statuses->acceptsSubmission($document, $existing, new \DateTimeImmutable('today'))) {
            $this->addFlash('error', 'dossierSubmissionClosedFlashMessage');

            return $this->back($dossier, $document);
        }

        $version = $this->submissions->nextVersion($document, $student);

        if (DossierDepositType::Url === $document->getDepositType()) {
            $url = PostValue::trimmed($request, 'url');

            if ('' === $url || \count($validator->validate($url, new Url(requireTld: true))) > 0) {
                $this->addFlash('error', 'dossierSubmissionInvalidUrlFlashMessage');

                return $this->back($dossier, $document);
            }

            $this->entityManager->persist(DossierSubmission::ofUrl($document, $student, $version, $url));
            $this->entityManager->flush();
            $this->addFlash('success', 'dossierSubmissionSavedFlashMessage');

            return $this->back($dossier, $document);
        }

        $form = $this->createForm(DossierSubmissionType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'dossierSubmissionInvalidFileFlashMessage');

            return $this->back($dossier, $document);
        }

        /** @var StagedUpload $file */
        $file = $form->get('file')->getData();

        $key = $uploads->store(
            $file,
            self::SUBMISSION_UPLOAD_PREFIX,
            \sprintf('%d-%d-%s.%s', $documentId, (int) $student->getId(), bin2hex(random_bytes(4)), UploadIntake::extension($file)),
        );

        $this->entityManager->persist(DossierSubmission::ofFile(
            $document,
            $student,
            $version,
            $key,
            UploadIntake::originalName($file),
            UploadIntake::size($file),
        ));
        $this->entityManager->flush();

        $this->addFlash('success', 'dossierSubmissionSavedFlashMessage');

        return $this->back($dossier, $document);
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param array<int, \App\Service\Dossier\DossierCell> $cells
     *
     * @return list<array{group: \App\Entity\DossierGroup|null, cells: list<\App\Service\Dossier\DossierCell>}>
     */
    private function groupedCells(Dossier $dossier, array $cells): array
    {
        $groups = [];

        foreach ([...$dossier->getGroups()->toArray(), null] as $group) {
            $ofGroup = [];

            foreach ($dossier->documentsOfGroup($group) as $document) {
                $id = $document->getId();

                if (null !== $id && isset($cells[$id])) {
                    $ofGroup[] = $cells[$id];
                }
            }

            if ([] !== $ofGroup) {
                $groups[] = ['group' => $group, 'cells' => $ofGroup];
            }
        }

        return $groups;
    }

    /**
     * @param array<int, \App\Service\Dossier\DossierCell> $cells
     *
     * @return array<int, bool>
     */
    private function acceptance(array $cells): array
    {
        $today = new \DateTimeImmutable('today');
        $accepts = [];

        foreach ($cells as $id => $cell) {
            $accepts[$id] = $this->statuses->acceptsSubmission($cell->document, $cell->submissions, $today);
        }

        return $accepts;
    }

    /**
     * « Prochaine échéance » — the nearest date limite still open on this cible's side.
     *
     * A settled document has no échéance left, and neither has one that is not visible yet: the
     * student is told what to do next, not what the calendar holds.
     *
     * @param array<int, \App\Service\Dossier\DossierCell> $cells
     */
    private function nextDeadline(array $cells): ?\DateTimeImmutable
    {
        $nearest = null;

        foreach ($cells as $cell) {
            $due = $cell->document->getDueOn();

            if ($cell->isSettled() || null === $due || \App\Enum\DossierDocumentStatus::Upcoming === $cell->status) {
                continue;
            }

            if (null === $nearest || $due < $nearest) {
                $nearest = $due;
            }
        }

        return $nearest;
    }

    /** @param array<int, \App\Service\Dossier\DossierCell> $cells */
    private function pendingCount(array $cells): int
    {
        return \count(array_filter(
            $cells,
            static fn ($cell): bool => !$cell->isSettled() && \App\Enum\DossierDocumentStatus::Upcoming !== $cell->status,
        ));
    }

    private function back(Dossier $dossier, DossierDocument $document): Response
    {
        return $this->redirectToRoute('app_my_dossier', ['id' => $dossier->getId(), 'document' => $document->getId()]);
    }

    private function dossierOr404(int $id): Dossier
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(DossierVoter::SUBMIT, $dossier)) {
            throw $this->createNotFoundException();
        }

        return $dossier;
    }

    private function documentOr404(Dossier $dossier, int $documentId): DossierDocument
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
