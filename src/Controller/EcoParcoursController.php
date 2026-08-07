<?php

namespace App\Controller;

use App\Entity\EcoParcours;
use App\Entity\User;
use App\Entity\EcoCheckpoint;
use App\Form\EcoParcoursCreateType;
use App\Repository\EcoParcoursRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\EcoParcoursFactory;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
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

    #[Route(path: '/eco/parcours/new', name: 'app_eco_parcours_new')]
    public function create(Request $request, EntityManagerInterface $entityManager, EcoParcoursFactory $factory): Response
    {
        $form = $this->createForm(EcoParcoursCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $parcours = $factory->create(
                $this->currentUser(),
                (string) $form->get('name')->getData(),
                (int) $form->get('checkpointCount')->getData(),
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
    public function configure(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $repository): Response
    {
        $parcours = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('eco_parcours_configure', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $tolerances = $request->request->all('tolerance');
            foreach ($parcours->getCheckpoints() as $checkpoint) {
                $key = (string) $checkpoint->getId();
                if (isset($tolerances[$key]) && '' !== $tolerances[$key]) {
                    $checkpoint->setToleranceMeters(max(1, (int) $tolerances[$key]));
                }
            }

            $parcours->setLastUpdatedBy($this->currentUser());
            $parcours->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'ecoParcoursUpdatedFlashMessage');

            return $this->redirectToRoute('app_eco_parcours_configure', ['id' => $parcours->getId()]);
        }

        return $this->render('eco/parcours_configure.html.twig', [
            'parcours' => $parcours,
            'mapCheckpoints' => $this->mapCheckpoints($parcours),
        ]);
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

    /** @return array{id: int, name: string, checkpointsLabel: string, statusValue: string, statusLabel: string, coursesLabel: string, updatedAt: string, isReady: bool} */
    private function rowForParcours(EcoParcours $parcours, TranslatorInterface $translator): array
    {
        $status = $parcours->getStatus();
        $courseCount = $parcours->getCourses()->count();
        $updatedAt = $parcours->getLastUpdatedDate() ?? $parcours->getCreationDate();

        return [
            'id' => $parcours->getId(),
            'name' => $parcours->getName() ?? '',
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
