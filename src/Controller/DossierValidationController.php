<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\DossierReview;
use App\Entity\User;
use App\Enum\DossierReviewAction;
use App\Enum\Feature;
use App\Repository\DossierRepository;
use App\Repository\DossierSubmissionRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierBoardBuilder;
use App\Service\Dossier\DossierCell;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Dépôts à valider » — the queue of one dossier and the détail of one dépôt.
 *
 * The queue is the dossier's, not the reader's: nothing routes a dépôt to a person, so any
 * validateur may answer any of them and answering one takes it out of everybody's list. That is a
 * decision rather than an omission - a dossier is followed by a small, named group, and assigning
 * pieces inside it would add a second thing to administer for no gain.
 *
 * The one rule this screen enforces that no other does: **a correction request must carry a
 * comment**. It is refused here, and refused again in App\Entity\DossierReview's constructor, which
 * is the one that matters - a correction with nothing to correct is a refusal the cible cannot act
 * on.
 */
#[RequiresFeature(Feature::Dossiers)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DossierValidationController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierBoardBuilder $boards,
        private readonly DossierSubmissionRepository $submissions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/dossiers/{id}/review', name: 'app_dossier_review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Request $request, int $id): Response
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(DossierVoter::VALIDATE, $dossier) || !$dossier->isPublished()) {
            throw $this->createNotFoundException();
        }

        $board = $this->boards->build($dossier);
        $queue = $board->validationQueue();
        $selected = $this->selected($queue, QueryValue::nullableInt($request, 'submission'));

        return $this->render('dossier/review.html.twig', [
            'dossier' => $dossier,
            'queue' => $queue,
            'selected' => $selected,
            // Every dépôt of the crossing, newest first: the historique is « Dépôt v2 · Correction
            // demandée · Dépôt v1 », read from the versions and their échanges rather than from a
            // log of its own.
            'history' => null === $selected ? [] : $this->history($selected),
        ]);
    }

    #[Route(path: '/dossiers/{id}/review/{submissionId}', name: 'app_dossier_review_decide', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['POST'])]
    public function decide(Request $request, int $id, int $submissionId): Response
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(DossierVoter::VALIDATE, $dossier)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('dossier_review', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }

        $submission = $this->submissions->find($submissionId) ?? throw $this->createNotFoundException();

        if ($submission->getDocument()?->getDossier()?->getId() !== $dossier->getId()) {
            throw $this->createNotFoundException();
        }

        $action = DossierReviewAction::tryFrom(PostValue::string($request, 'action')) ?? throw $this->createNotFoundException();
        $comment = PostValue::trimmed($request, 'comment');

        if ($action->requiresComment() && '' === $comment) {
            $this->addFlash('error', 'dossierReviewCommentRequiredFlashMessage');

            return $this->redirectToRoute('app_dossier_review', ['id' => $dossier->getId(), 'submission' => $submissionId]);
        }

        $this->entityManager->persist(new DossierReview($submission, $this->currentUser(), $action, '' === $comment ? null : $comment));
        $this->entityManager->flush();

        $this->addFlash('success', $action->requiresComment() ? 'dossierReviewCorrectionSentFlashMessage' : 'dossierReviewValidatedFlashMessage');

        // Back to the queue with nothing selected: the dépôt just answered has left it, and the
        // next one is what the validateur is here for.
        return $this->redirectToRoute('app_dossier_review', ['id' => $dossier->getId()]);
    }

    /**
     * @param list<DossierCell> $queue
     */
    private function selected(array $queue, ?int $submissionId): ?DossierCell
    {
        if (null !== $submissionId) {
            foreach ($queue as $cell) {
                if ($cell->latest?->getId() === $submissionId) {
                    return $cell;
                }
            }
        }

        return $queue[0] ?? null;
    }

    /**
     * The vertical historique of one crossing — the dépôts and the échanges between them, newest
     * first, closed by the day the document became visible.
     *
     * @return list<array{kind: string, title: string, author: ?User, at: \DateTimeInterface, comment: ?string}>
     */
    private function history(DossierCell $cell): array
    {
        $entries = [];

        foreach ($cell->submissions as $submission) {
            $entries[] = [
                'kind' => 'submission',
                'title' => 'v'.$submission->getVersion(),
                'author' => $submission->getStudent(),
                'at' => $submission->getSubmittedAt(),
                'comment' => null,
            ];

            foreach ($submission->getReviews() as $review) {
                $entries[] = [
                    'kind' => $review->isValidation() ? 'validated' : 'correction',
                    'title' => '',
                    'author' => $review->getAuthor(),
                    'at' => $review->getCreatedAt(),
                    'comment' => $review->getComment(),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $visibleFrom = $cell->document->getVisibleFrom();

        if (null !== $visibleFrom) {
            $entries[] = ['kind' => 'visible', 'title' => '', 'author' => null, 'at' => $visibleFrom, 'comment' => null];
        }

        return $entries;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
