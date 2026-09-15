<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\LaptopConditionType;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\LaptopImportStartType;
use App\Repository\LaptopConditionTypeRepository;
use App\Service\LaptopImport\LaptopImportAnalysis;
use App\Service\LaptopImport\LaptopImportAnalyzer;
use App\Service\LaptopImport\LaptopImportCsvReader;
use App\Service\LaptopImport\LaptopImportExecutor;
use App\Service\LaptopImport\LaptopImportFileException;
use App\Service\LaptopImport\LaptopImportNotExecutableException;
use App\Service\LaptopImport\LaptopImportOutcome;
use App\Service\LaptopImport\LaptopRow;
use App\Service\UploadIntake;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Ordinateurs portables > Inventaire > Importer une liste » - a delivery of machines loaded into
 * the fleet in one go, in three deliberate moves: ① fichier · ② vérification, validée par
 * l'opérateur · ③ récapitulatif.
 *
 * The middle step is the feature, and it is the same shape as the class import and the UFA contract
 * import for the same reason. Nothing is written while it is on screen, and one blocking finding
 * refuses the entire file rather than dropping the offending line: an inventory that half-imported
 * cannot be reconciled against the spreadsheet it came from, and the two numbers this screen is
 * about are both unique - a half-written file cannot simply be uploaded again.
 *
 * The parsed rows live in the session between ② and ③ (never the uploaded file itself), and ③
 * replays the analysis from them before writing - somebody may have added one of these machines in
 * the meantime.
 *
 * Same access as the rest of the Ordinateurs portables area: adding a box of machines to the
 * inventory is the same act as « Ajouter un ordinateur », done thirty times.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::LaptopLoans)]
class LaptopImportController extends AbstractController
{
    /** The parsed file waiting for its analysis to be confirmed. */
    private const string ROWS_SESSION_KEY = 'laptop_import_rows';

    /** The file's own name, kept only so every screen can say which file this is about. */
    private const string FILE_SESSION_KEY = 'laptop_import_file';

    /** The état step ① chose for every machine the import creates, as an id. */
    private const string CONDITION_SESSION_KEY = 'laptop_import_condition';

    /** What the last confirmed import actually wrote - read once by the recap, then dropped. */
    private const string OUTCOME_SESSION_KEY = 'laptop_import_outcome';

    #[Route(path: '/laptops/import', name: 'app_laptops_import', methods: ['GET', 'POST'])]
    public function start(Request $request, LaptopImportCsvReader $reader, UploadIntake $uploadIntake, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(LaptopImportStartType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The bytes are already in the bucket (the field stages them - see
            // App\Form\FilePickerType), so they are fetched back rather than read off an upload.
            $file = $uploadIntake->asLocalFile($form->get('file')->getData());

            try {
                $content = @file_get_contents($file->getPathname());
                if (false === $content) {
                    throw new LaptopImportFileException('laptopImportFileEmptyMessage');
                }

                $rows = $reader->read($content);
                $condition = $form->get('initialConditionType')->getData();

                $session = $request->getSession();
                $session->set(self::ROWS_SESSION_KEY, array_map(static fn (LaptopRow $row): array => $row->toArray(), $rows));
                $session->set(self::FILE_SESSION_KEY, $file->getClientOriginalName());
                $session->set(self::CONDITION_SESSION_KEY, $condition instanceof LaptopConditionType ? $condition->getId() : null);

                return $this->redirectToRoute('app_laptops_import_check');
            } catch (LaptopImportFileException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('laptop/import/start.html.twig', ['form' => $form]);
    }

    #[Route(path: '/laptops/import/check', name: 'app_laptops_import_check', methods: ['GET'])]
    public function check(Request $request, LaptopImportAnalyzer $analyzer, LaptopConditionTypeRepository $conditionTypes): Response
    {
        $analysis = $this->analyzeSession($request, $analyzer);

        if (null === $analysis) {
            return $this->redirectToRoute('app_laptops_import');
        }

        return $this->render('laptop/import/check.html.twig', [
            'analysis' => $analysis,
            'initialCondition' => $this->sessionCondition($request, $conditionTypes),
        ]);
    }

    /**
     * ③ - the only writing move.
     *
     * The analysis is rebuilt from the parked rows rather than trusted from what the operator was
     * shown: between the two screens somebody may have added one of these machines, which the two
     * unique indexes would then turn into a failed flush rather than a refusal anyone can read.
     */
    #[Route(path: '/laptops/import/confirm', name: 'app_laptops_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        LaptopImportAnalyzer $analyzer,
        LaptopImportExecutor $executor,
        LaptopConditionTypeRepository $conditionTypes,
    ): Response {
        $this->assertValidFormToken('laptop_import_confirm', $request);

        // The checkbox carries `required`, but a required attribute is a browser courtesy - the
        // operator's explicit "cette analyse correspond au fichier" is the only thing standing
        // between a spreadsheet and a fleet, so it is checked here too.
        if (null === $request->request->get('confirmed')) {
            $this->addFlash('warning', 'laptopImportNotConfirmedFlashMessage');

            return $this->redirectToRoute('app_laptops_import_check');
        }

        $analysis = $this->analyzeSession($request, $analyzer);

        if (null === $analysis) {
            return $this->redirectToRoute('app_laptops_import');
        }

        /** @var User $operator */
        $operator = $this->getUser();

        try {
            $outcome = $executor->execute($analysis, $operator, $this->sessionCondition($request, $conditionTypes));
        } catch (LaptopImportNotExecutableException) {
            $this->addFlash('danger', 'laptopImportNoLongerImportableFlashMessage');

            return $this->redirectToRoute('app_laptops_import_check');
        }

        $session = $request->getSession();
        $session->remove(self::ROWS_SESSION_KEY);
        $session->remove(self::CONDITION_SESSION_KEY);
        $session->set(self::OUTCOME_SESSION_KEY, [
            'fileName' => $analysis->fileName,
            'createdAssetTags' => $outcome->createdAssetTags,
            'skippedAssetTags' => $outcome->skippedAssetTags,
        ]);

        return $this->redirectToRoute('app_laptops_import_result');
    }

    #[Route(path: '/laptops/import/result', name: 'app_laptops_import_result', methods: ['GET'])]
    public function result(Request $request): Response
    {
        $session = $request->getSession();
        $stored = $session->get(self::OUTCOME_SESSION_KEY);

        if (!\is_array($stored)) {
            return $this->redirectToRoute('app_laptops_import');
        }

        // Read once: a refresh (or a bookmark) must not keep showing a report of an import that
        // happened at some point in the past as if it had just run.
        $session->remove(self::OUTCOME_SESSION_KEY);
        $session->remove(self::FILE_SESSION_KEY);

        $tags = static fn (mixed $value): array => array_values(array_filter(\is_array($value) ? $value : [], \is_string(...)));

        return $this->render('laptop/import/result.html.twig', [
            'fileName' => \is_string($stored['fileName'] ?? null) ? $stored['fileName'] : '',
            'outcome' => new LaptopImportOutcome($tags($stored['createdAssetTags'] ?? null), $tags($stored['skippedAssetTags'] ?? null)),
        ]);
    }

    /**
     * The file an operator starts from. Carries a BOM so a double-click into Excel reads the accents
     * rather than showing mojibake - the same file then comes back through App\Service\CsvTable,
     * which strips it again.
     *
     * Headers spelled the way the school's own inventory export spells them, which is also what the
     * reader's aliases are built around.
     */
    #[Route(path: '/laptops/import/template.csv', name: 'app_laptops_import_template', methods: ['GET'])]
    public function template(): Response
    {
        $csv = "\xEF\xBB\xBFN° inventaire;N° série;Marque;Modèle;Prix garantie\r\n"
            ."2026/01;5CD335G7GT;HP;PAVILION;500\r\n";

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="modele-import-ordinateurs.csv"');

        return $response;
    }

    /** Null means "nothing usable in the session" - the caller sends the operator back to step ①. */
    private function analyzeSession(Request $request, LaptopImportAnalyzer $analyzer): ?LaptopImportAnalysis
    {
        $session = $request->getSession();
        $stored = $session->get(self::ROWS_SESSION_KEY);

        if (!\is_array($stored) || [] === $stored) {
            $this->addFlash('warning', 'laptopImportSessionExpiredFlashMessage');

            return null;
        }

        $rows = array_values(array_map(
            static fn (mixed $row): LaptopRow => LaptopRow::fromArray(\is_array($row) ? $row : []),
            $stored,
        ));

        $fileName = $session->get(self::FILE_SESSION_KEY);

        return $analyzer->analyze($rows, \is_string($fileName) ? $fileName : '');
    }

    private function sessionCondition(Request $request, LaptopConditionTypeRepository $conditionTypes): ?LaptopConditionType
    {
        $id = $request->getSession()->get(self::CONDITION_SESSION_KEY);

        return \is_int($id) ? $conditionTypes->find($id) : null;
    }

    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
