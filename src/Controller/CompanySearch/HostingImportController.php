<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\EnterpriseHostingImportType;
use App\Security\Voter\EnterpriseVoter;
use App\Service\EnterprisePool\Import\HostingImportAnalysis;
use App\Service\EnterprisePool\Import\HostingImportAnalyzer;
use App\Service\EnterprisePool\Import\HostingImportExecutor;
use App\Service\EnterprisePool\Import\HostingImportFileException;
use App\Service\EnterprisePool\Import\HostingImportReader;
use App\Service\EnterprisePool\Import\HostingImportRow;
use App\Service\UploadIntake;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Importer l'historique » (design/validated/vivier-entreprises.md §7.2): ① the file · ② the
 * analysis, nothing written · ③ the import, re-analysed from the session before a single row is
 * written. ROLE_ADMIN alone. The parsed rows live in the session, never the file itself.
 */
#[RequiresFeature(Feature::EnterprisePool)]
class HostingImportController extends AbstractController
{
    private const string ROWS_SESSION_KEY = 'enterprise_hosting_import_rows';
    private const string FILE_SESSION_KEY = 'enterprise_hosting_import_file';

    #[Route(path: '/enterprises/import', name: 'app_enterprise_pool_import', methods: ['GET', 'POST'])]
    public function upload(Request $request, UploadIntake $uploadIntake, HostingImportReader $reader, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $form = $this->createForm(EnterpriseHostingImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $uploadIntake->asLocalFile($form->get('file')->getData());
            try {
                $rows = $reader->read($file->getPathname(), $file->getClientOriginalName());
                $request->getSession()->set(self::ROWS_SESSION_KEY, array_map(static fn (HostingImportRow $row): array => $row->toArray(), $rows));
                $request->getSession()->set(self::FILE_SESSION_KEY, $file->getClientOriginalName());

                return $this->redirectToRoute('app_enterprise_pool_import_analysis');
            } catch (HostingImportFileException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('company_search/import_upload.html.twig', [
            'form' => $form,
            'columns' => HostingImportRow::COLUMNS,
        ]);
    }

    /** The model file: the columns, and one line of each kind as an example. */
    #[Route(path: '/enterprises/import/template.csv', name: 'app_enterprise_pool_import_template', methods: ['GET'])]
    public function template(): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $lines = [
            HostingImportRow::COLUMNS,
            ['stage', '2023-2024', 'SIO', 'SLAM', 'Prénom Nom', 'Néopixel', '', '14 rue Exemple', '87000', 'Limoges', 'Julie Exemple', 'Responsable technique', 'julie@exemple.fr', '05 55 00 00 00', 'Application de gestion des stocks'],
            ['alternance', '2021-2022', 'SIO', 'SISR', 'Prénom Nom', 'Clinique Exemple', '12345678900012', '', '87100', 'Limoges', '', '', '', '', 'Administration du parc'],
        ];
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($handle, $line, ';', '"', '');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-historique-vivier.csv"',
        ]);
    }

    #[Route(path: '/enterprises/import/analysis', name: 'app_enterprise_pool_import_analysis', methods: ['GET'])]
    public function analysis(Request $request, HostingImportAnalyzer $analyzer): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $analysis = $this->analyzeSession($request, $analyzer);
        if (null === $analysis) {
            return $this->redirectToRoute('app_enterprise_pool_import');
        }

        return $this->render('company_search/import_analysis.html.twig', [
            'analysis' => $analysis,
            'fileName' => $request->getSession()->get(self::FILE_SESSION_KEY),
        ]);
    }

    #[Route(path: '/enterprises/import/confirm', name: 'app_enterprise_pool_import_confirm', methods: ['POST'])]
    public function confirm(Request $request, HostingImportAnalyzer $analyzer, HostingImportExecutor $executor, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        if (!$this->isCsrfTokenValid('enterprise_hosting_import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $analysis = $this->analyzeSession($request, $analyzer);
        if (null === $analysis) {
            return $this->redirectToRoute('app_enterprise_pool_import');
        }
        if (!$analysis->isImportable()) {
            $this->addFlash('warning', 'enterpriseImportBlockedFlash');

            return $this->redirectToRoute('app_enterprise_pool_import_analysis');
        }

        $user = $this->getUser();
        \assert($user instanceof User);
        $counts = $executor->execute($analysis, $user);
        $request->getSession()->remove(self::ROWS_SESSION_KEY);
        $request->getSession()->remove(self::FILE_SESSION_KEY);
        $this->addFlash('success', $translator->trans('enterpriseImportDoneFlash', [
            '%hostings%' => $counts['hostings'],
            '%enterprises%' => $counts['enterprises'],
            '%contacts%' => $counts['contacts'],
        ]));

        return $this->redirectToRoute('app_enterprise_pool');
    }

    private function analyzeSession(Request $request, HostingImportAnalyzer $analyzer): ?HostingImportAnalysis
    {
        $stored = $request->getSession()->get(self::ROWS_SESSION_KEY);
        if (!\is_array($stored) || [] === $stored) {
            return null;
        }

        $rows = [];
        foreach ($stored as $row) {
            if (\is_array($row)) {
                $rows[] = HostingImportRow::fromArray($row);
            }
        }
        $user = $this->getUser();
        \assert($user instanceof User);

        return $analyzer->analyze($rows, $user);
    }
}
