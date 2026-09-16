<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Enum\DossierDocumentStatus;
use App\Enum\Feature;
use App\Repository\DossierRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierBoardBuilder;
use App\Service\Dossier\DossierCell;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Suivi » — one screen, three readings of the same board.
 *
 * The three tabs, the document selector, the status filter and the open row are all **query
 * parameters**, not browser state. Three reasons, in order of how much they cost when they are
 * missing: a teacher can send « regarde la colonne Rapport de stage » as a link; the back button
 * returns to the reading you were in; and the screen needs no JavaScript to be correct, which is
 * what makes it work identically in the printed view.
 *
 * Every filter is read through App\Service\QueryValue: the status pills submit `?status=` empty for
 * « Tous », and `InputBag::getInt()` answers a 400 to the empty string - the gotcha that once took
 * four screens down at the same time.
 */
#[RequiresFeature(Feature::Dossiers)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DossierTrackingController extends AbstractController
{
    private const string TAB_GRID = 'grid';
    private const string TAB_CIBLES = 'cibles';
    private const string TAB_DOCUMENT = 'document';

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierBoardBuilder $boards,
    ) {
    }

    #[Route(path: '/dossiers/{id}/tracking', name: 'app_dossier_tracking', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Request $request, int $id): Response
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(DossierVoter::VIEW, $dossier) || !$dossier->isPublished()) {
            throw $this->createNotFoundException();
        }

        $board = $this->boards->build($dossier);
        $tab = $this->tab(QueryValue::trimmed($request, 'tab'));
        $document = $this->document($dossier, QueryValue::nullableInt($request, 'document'));
        $status = DossierDocumentStatus::tryFrom(QueryValue::trimmed($request, 'status'));

        $cells = null === $document ? [] : $board->cellsOfDocument($document);

        return $this->render('dossier/tracking.html.twig', [
            'dossier' => $dossier,
            'board' => $board,
            'tab' => $tab,
            'document' => $document,
            'status' => $status,
            'openCible' => QueryValue::nullableInt($request, 'cible'),
            'documentCells' => null === $status ? $cells : array_values(array_filter(
                $cells,
                static fn (DossierCell $cell): bool => $cell->status === $status,
            )),
            'statusCounts' => $this->statusCounts($cells),
            'queueSize' => \count($board->validationQueue()),
            'toCorrectStatus' => DossierDocumentStatus::ToCorrect,
            'lateStatus' => DossierDocumentStatus::Late,
            'filterableStatuses' => DossierDocumentStatus::filterable(),
            'legendStatuses' => DossierDocumentStatus::legend(),
        ]);
    }

    /** @param list<DossierCell> $cells */
    private function statusCounts(array $cells): array
    {
        $counts = ['' => \count($cells)];

        foreach (DossierDocumentStatus::filterable() as $status) {
            $counts[$status->value] = \count(array_filter($cells, static fn (DossierCell $cell): bool => $cell->status === $status));
        }

        return $counts;
    }

    private function tab(string $requested): string
    {
        return \in_array($requested, [self::TAB_GRID, self::TAB_CIBLES, self::TAB_DOCUMENT], true) ? $requested : self::TAB_GRID;
    }

    /**
     * The document the « Par document » tab is reading.
     *
     * Falls back on the first of the dossier rather than answering nothing: the tab is a reading of
     * one document, so a missing or forged id lands on a document instead of on an empty screen.
     */
    private function document(Dossier $dossier, ?int $requested): ?DossierDocument
    {
        if (null !== $requested) {
            foreach ($dossier->getDocuments() as $document) {
                if ($document->getId() === $requested) {
                    return $document;
                }
            }
        }

        return $dossier->getDocuments()->first() ?: null;
    }
}
