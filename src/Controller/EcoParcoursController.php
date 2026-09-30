<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\EcoParcoursCreateType;
use App\Repository\EcoParcoursRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\Eco\EcoParcoursSharing;
use App\Service\Eco\EcoToleranceAdvisor;
use App\Service\Eco\EcoToleranceEditor;
use App\Service\EcoParcoursFactory;
use App\Service\FormValue;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

// e-CO teacher web side - see design/design_campus_manager/README.md "e-CO" section and
// reference/e-CO.dc.html screens 1d/1e. ROLE_ECO is a manually-granted, non-LDAP-synced role
// (App\Entity\Group with ldapCn null, granted via Settings > Groupes) - not everyone with
// ROLE_TEACHER gets e-CO, unlike the quiz/séquences library.
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Eco)]
class EcoParcoursController extends AbstractController
{
    // Rendered server-side, without DataTables: screen 1d shows neither a search box, nor a
    // page-length select, nor pagination - just the table and its "N parcours" footer - and a
    // teacher's own parcours list is small (same reasoning as App\Enum\EcoParcoursStatus).
    #[Route(path: '/eco/parcours', name: 'app_eco_parcours')]
    public function list(EcoParcoursRepository $repository, TranslatorInterface $translator): Response
    {
        return $this->render('eco/parcours_list.html.twig', [
            'rows' => array_map(
                fn (EcoParcours $parcours): array => $this->rowForParcours($parcours, $translator),
                $repository->findForTeacher($this->currentUser()),
            ),
        ]);
    }

    /**
     * The sharing picker's ajax endpoint (tom-select): teachers who run e-CO, never the viewer nor
     * the parcours' creator. The save re-checks every id - a picker is a convenience, never the
     * control (EcoParcoursSharing::apply()).
     */
    #[Route(path: '/eco/parcours/share/candidates', name: 'app_eco_parcours_share_candidates', methods: ['GET'])]
    public function shareCandidates(Request $request, EcoParcoursRepository $repository, EcoParcoursSharing $sharing): JsonResponse
    {
        $parcours = null;
        $parcoursId = QueryValue::nullableInt($request, 'parcours');
        if (null !== $parcoursId) {
            $parcours = $this->findOrNotFound($repository, $parcoursId);
            $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);
        }

        $limit = 20;
        $candidates = $sharing->candidates($this->currentUser(), $parcours, QueryValue::trimmed($request, 'q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    // « Partage » card of screen 1e: whoever may edit the parcours may rewrite who else holds it.
    #[Route(path: '/eco/parcours/{id}/share', name: 'app_eco_parcours_share', methods: ['POST'])]
    public function share(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $repository, EcoParcoursSharing $sharing): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        if (!$this->isCsrfTokenValid('eco_parcours_share', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sharing->apply($parcours, PostValue::intList($request, 'sharedWith'), $this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'ecoParcoursSharedFlashMessage');

        // Someone who took themselves off the list no longer reaches the screen they were on.
        if (!$this->isGranted(EcoParcoursVoter::EDIT, $parcours)) {
            return $this->redirectToRoute('app_eco_parcours');
        }

        return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId(), '_fragment' => 'eco-share']);
    }

    #[Route(path: '/eco/parcours/new', name: 'app_eco_parcours_new')]
    public function create(Request $request, EntityManagerInterface $entityManager, EcoParcoursFactory $factory): Response
    {
        $form = $this->createForm(EcoParcoursCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $parcours = $factory->create(
                $this->currentUser(),
                FormValue::string($form, 'name'),
                FormValue::int($form, 'checkpointCount'),
            );
            $entityManager->flush();

            $this->addFlash('success', 'ecoParcoursCreatedFlashMessage');

            return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId()]);
        }

        return $this->render('eco/parcours_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/eco/parcours/{id}/configure', name: 'app_eco_parcours_configure')]
    public function configure(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $repository, EcoToleranceAdvisor $toleranceAdvisor, EcoToleranceEditor $toleranceEditor): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('eco_parcours_configure', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $toleranceEditor->apply($parcours, PostValue::all($request, 'tolerance'), $this->currentUser());
            $entityManager->flush();

            $this->addFlash('success', 'ecoParcoursUpdatedFlashMessage');

            return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId()]);
        }

        return $this->render('eco/parcours_configure.html.twig', [
            'parcours' => $parcours,
            'mapCheckpoints' => $this->mapCheckpoints($parcours),
            'terrain' => $parcours->getTerrainAnalysis(),
            'terrainIsCurrent' => $parcours->hasCurrentTerrainAnalysis(),
            'toleranceAdvice' => $this->toleranceAdvice($parcours, $toleranceAdvisor),
        ]);
    }

    /**
     * « Analyser le terrain »: asks app:eco:read-terrain for the IGN's reading of the parcours. It
     * is not done here - the BD TOPO answers in seconds per layer - so the screen says it is under
     * way and reloads itself once it is written (eco_terrain_pending_controller.js).
     */
    #[Route(path: '/eco/parcours/{id}/terrain', name: 'app_eco_parcours_terrain_request', methods: ['POST'])]
    public function requestTerrain(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $repository): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        if (!$this->isCsrfTokenValid('eco_parcours_terrain', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($parcours->getLocatedCheckpointCount() > 0) {
            $parcours->requestTerrainAnalysis(new \DateTimeImmutable());
            $entityManager->flush();
            $this->addFlash('success', 'ecoTerrainRequestedFlashMessage');
        }

        return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId(), '_fragment' => 'eco-terrain']);
    }

    // Polled by the configuration screen while an analysis is pending: it reloads once this says no.
    #[Route(path: '/eco/parcours/{id}/terrain/status', name: 'app_eco_parcours_terrain_status', methods: ['GET'])]
    public function terrainStatus(int $id, EcoParcoursRepository $repository): JsonResponse
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        return $this->json(['pending' => null !== $parcours->getTerrainRequestedAt()]);
    }

    /**
     * The tolerance each flag should have under its canopy, when it has less.
     *
     * @return array<int, int> checkpoint id => advised metres
     */
    private function toleranceAdvice(EcoParcours $parcours, EcoToleranceAdvisor $advisor): array
    {
        $advice = [];
        foreach ($parcours->getCheckpoints() as $checkpoint) {
            $metres = $advisor->adviceFor($checkpoint);
            if (null !== $metres) {
                $advice[(int) $checkpoint->getId()] = $metres;
            }
        }

        return $advice;
    }

    /**
     * The markers of screen 1e's parcours map. A checkpoint that has never been scanned on the
     * ground has no coordinates, so it simply has no marker - the "à localiser" count in the
     * legend is what stands for those.
     *
     * @return list<array{label: string, isAnchor: bool, latitude: float, longitude: float, lines: list<string>}>
     */
    private function mapCheckpoints(EcoParcours $parcours): array
    {
        $markers = [];
        foreach ($parcours->getCheckpoints() as $checkpoint) {
            if (!$checkpoint->isLocated()) {
                continue;
            }

            $letter = $checkpoint->getType()->shortLetter();
            $markers[] = [
                'label' => $letter ?? (string) $checkpoint->getPosition(),
                'isAnchor' => null !== $letter,
                'latitude' => (float) $checkpoint->getLatitude(),
                'longitude' => (float) $checkpoint->getLongitude(),
                'lines' => array_values(array_filter([
                    $checkpoint->getName() ?? '',
                    $checkpoint->getShortCode() ?? '',
                    $checkpoint->getLocatedAt()?->format('d/m/Y H:i'),
                ])),
            ];
        }

        return $markers;
    }

    // "1 page A4 par balise" export (screen 1f) - each checkpoint's page is rendered/converted to
    // PDF individually then merged in position order, since GotenbergClient only converts one
    // standalone HTML document at a time (see App\Service\GotenbergClient's own docblock).
    #[Route(path: '/eco/parcours/{id}/pdf', name: 'app_eco_parcours_pdf')]
    public function pdf(int $id, EcoParcoursRepository $repository, GotenbergClient $gotenbergClient): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        $checkpoints = $parcours->getCheckpoints()->toArray();
        $total = \count($checkpoints);

        if (0 === $total) {
            throw $this->createNotFoundException();
        }

        try {
            $pdfs = array_map(
                fn (EcoCheckpoint $checkpoint, int $index): string => $this->renderCheckpointPdf($parcours->getName() ?? '', $checkpoint, $index + 1, $total, $gotenbergClient),
                $checkpoints,
                array_keys($checkpoints),
            );
            $merged = $gotenbergClient->mergePdfs($pdfs);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'ecoParcoursExportPdfFailedFlashMessage');

            return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId()]);
        }

        $filename = (new AsciiSlugger())->slug($parcours->getName() ?? 'parcours')->lower()->toString();

        return new Response($merged, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('eco-balises-%s.pdf', $filename)),
        ]);
    }

    private function renderCheckpointPdf(string $parcoursName, EcoCheckpoint $checkpoint, int $pageNumber, int $pageTotal, GotenbergClient $gotenbergClient): string
    {
        $qrSvg = (new Builder(
            writer: new SvgWriter(),
            data: (string) $checkpoint->getShortCode(),
            size: 600,
            margin: 10,
        ))->build()->getString();

        $html = $this->renderView('eco/_checkpoint_pdf_page.html.twig', [
            'parcours' => ['name' => $parcoursName],
            'checkpoint' => $checkpoint,
            'qrSvg' => $qrSvg,
            'pageNumber' => $pageNumber,
            'pageTotal' => $pageTotal,
        ]);

        return $gotenbergClient->convertHtmlToPdf($html);
    }

    // The token travels in the request body, not an X-CSRF-Token header: this is a plain POST form
    // in the row now, not a fetch() from the DataTables controller.
    #[Route(path: '/eco/parcours/{id}/remove', name: 'app_eco_parcours_remove', methods: ['POST'])]
    public function remove(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $repository): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        if (!$this->isCsrfTokenValid('eco_parcours_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Checkpoints follow the parcours (orphanRemoval), courses do not - deleting a parcours
        // that has already hosted one hits the foreign key instead of silently taking the courses
        // and their scan events down with it.
        try {
            $entityManager->remove($parcours);
            $entityManager->flush();
            $this->addFlash('success', 'ecoParcoursRemovedFlashMessage');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'ecoParcoursRemoveErrorMessage');
        }

        return $this->redirectToRoute('app_eco_parcours');
    }

    /** @return array{id: int, name: string, sharedByLabel: ?string, sharedWithCount: int, checkpointsLabel: string, statusValue: string, statusLabel: string, coursesLabel: string, updatedAt: string, isReady: bool} */
    private function rowForParcours(EcoParcours $parcours, TranslatorInterface $translator): array
    {
        $creator = $parcours->getTeacher();

        $status = $parcours->getStatus();
        $courseCount = $parcours->getCourses()->count();
        $updatedAt = $parcours->getLastUpdatedDate() ?? $parcours->getCreationDate();

        return [
            'id' => $parcours->getId(),
            'name' => $parcours->getName() ?? '',
            // A parcours someone else created, shared with the reader: the list says by whom.
            'sharedByLabel' => $creator !== $this->currentUser() && null !== $creator
                ? $translator->trans('ecoParcoursSharedByLabel', ['%name%' => $creator->getDisplayName() ?? $creator->getUsername()])
                : null,
            'sharedWithCount' => $parcours->getSharedWith()->count(),
            'checkpointsLabel' => \sprintf('%d + D/A', \count($parcours->getRegularCheckpoints())),
            'statusValue' => $status->value,
            'statusLabel' => $this->statusLabel($parcours, $translator),
            'coursesLabel' => $courseCount > 0 ? $translator->trans('ecoParcoursCourseCountLabel', ['%count%' => $courseCount]) : '—',
            'updatedAt' => $updatedAt->format('d/m/Y'),
            'isReady' => $parcours->isReady(),
        ];
    }

    private function statusLabel(EcoParcours $parcours, TranslatorInterface $translator): string
    {
        $status = $parcours->getStatus();
        if (\App\Enum\EcoParcoursStatus::ToLocate === $status) {
            return $translator->trans('ecoParcoursStatusToLocateWithCountLabel', [
                '%located%' => $parcours->getLocatedCheckpointCount(),
                '%total%' => $parcours->getCheckpoints()->count(),
            ]);
        }

        if (\App\Enum\EcoParcoursStatus::Ready === $status) {
            return $translator->trans('ecoParcoursStatusReadyWithCountLabel', [
                '%total%' => $parcours->getCheckpoints()->count(),
            ]);
        }

        return $translator->trans($status->labelKey());
    }

    private function findOrNotFound(EcoParcoursRepository $repository, int $id): EcoParcours
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
