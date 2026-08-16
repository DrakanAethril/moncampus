<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Form\UfaContractImportType;
use App\Service\AlternanceImport\ContractRow;
use App\Service\AlternanceImport\ContractSpreadsheetReader;
use App\Service\AlternanceImport\ImportAnalysis;
use App\Service\AlternanceImport\ImportAnalyzer;
use App\Service\AlternanceImport\ImportExecutor;
use App\Service\AlternanceImport\ImportFileException;
use App\Service\AlternanceImport\ImportOutcome;
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
 * « UFA > Configuration > Importer des contrats » - the bulk load of the school's apprenticeship
 * export into alternances, in three deliberate moves: ① dépôt du fichier · ② analyse à blanc,
 * validée par l'opérateur · ③ import.
 *
 * The middle step is the feature. Nothing is written while it is on screen, and the screen shows
 * the whole verdict - unknown students, employers and tutors about to be created, dates outside the
 * formation, alternances already held - because an import that writes 52 contracts, 46 employers
 * and 49 accounts on a single click is not something to be sorry about afterwards. One blocking
 * finding refuses the entire file rather than dropping the offending line: a file that half
 * imported is the state nobody can reason about.
 *
 * The parsed rows live in the session between ② and ③ (never the uploaded file itself), and ③
 * re-runs the analysis from them before writing - see ImportExecutor, which refuses anything that
 * has stopped being importable in the meantime.
 */
#[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
class ContractImportController extends AbstractController
{
    use UfaAlternanceTrait;

    /** The parsed file waiting for its analysis to be confirmed. */
    private const string ROWS_SESSION_KEY = 'ufa_contract_import_rows';

    /** The file's own name, kept only so both screens can say which file this is about. */
    private const string FILE_SESSION_KEY = 'ufa_contract_import_file';

    /** What the last confirmed import actually wrote - read once by the recap, then dropped. */
    private const string OUTCOME_SESSION_KEY = 'ufa_contract_import_outcome';

    #[Route(path: '/ufa/configuration/contract-import', name: 'app_ufa_configuration_contract_import', methods: ['GET', 'POST'])]
    public function upload(Request $request, ContractSpreadsheetReader $reader, TranslatorInterface $translator, UploadIntake $uploadIntake): Response
    {
        $form = $this->createForm(UfaContractImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The bytes are already in the bucket (the field stages them - see
            // App\Form\FilePickerType) and the reader wants a path: asLocalFile() fetches them back.
            $file = $uploadIntake->asLocalFile($form->get('file')->getData());

            try {
                $rows = $reader->read($file->getPathname());

                $session = $request->getSession();
                $session->set(self::ROWS_SESSION_KEY, array_map(static fn (ContractRow $row): array => $row->toArray(), $rows));
                $session->set(self::FILE_SESSION_KEY, $file->getClientOriginalName());

                return $this->redirectToRoute('app_ufa_configuration_contract_import_analysis');
            } catch (ImportFileException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('ufa/contract_import/upload.html.twig', ['form' => $form]);
    }

    #[Route(path: '/ufa/configuration/contract-import/analysis', name: 'app_ufa_configuration_contract_import_analysis', methods: ['GET'])]
    public function analysis(Request $request, ImportAnalyzer $analyzer, TranslatorInterface $translator): Response
    {
        $analysis = $this->analyzeSessionRows($request, $analyzer, $translator);

        if (null === $analysis) {
            return $this->redirectToRoute('app_ufa_configuration_contract_import');
        }

        return $this->render('ufa/contract_import/analysis.html.twig', ['analysis' => $analysis]);
    }

    #[Route(path: '/ufa/configuration/contract-import/confirm', name: 'app_ufa_configuration_contract_import_confirm', methods: ['POST'])]
    public function confirm(Request $request, ImportAnalyzer $analyzer, ImportExecutor $executor, TranslatorInterface $translator): Response
    {
        $this->assertValidFormToken('ufa_contract_import_confirm', $request);

        // The checkbox carries `required`, but a required attribute is a browser courtesy - the
        // operator's explicit "cette analyse correspond au fichier" is the only thing standing
        // between a spreadsheet and 52 contracts, so it is checked here too.
        if (null === $request->request->get('confirmed')) {
            $this->addFlash('warning', 'ufaContractImportNotConfirmedFlashMessage');

            return $this->redirectToRoute('app_ufa_configuration_contract_import_analysis');
        }

        // Re-analysed from the parked rows rather than trusting what the operator was shown: the
        // database may have moved on since (someone else creating one of these alternances is
        // precisely the case the tutor-conflict rule exists for).
        $analysis = $this->analyzeSessionRows($request, $analyzer, $translator);

        if (null === $analysis) {
            return $this->redirectToRoute('app_ufa_configuration_contract_import');
        }

        if (!$analysis->isImportable()) {
            $this->addFlash('danger', 'ufaContractImportNoLongerImportableFlashMessage');

            return $this->redirectToRoute('app_ufa_configuration_contract_import_analysis');
        }

        $outcome = $executor->execute($analysis, $this->currentUser());

        $session = $request->getSession();
        $session->remove(self::ROWS_SESSION_KEY);
        $session->set(self::OUTCOME_SESSION_KEY, [
            'fileName' => $analysis->fileName,
            'createdAlternances' => $outcome->createdAlternances,
            'createdEnterpriseNames' => $outcome->createdEnterpriseNames,
            'createdTutorLabels' => $outcome->createdTutorLabels,
            'taggedStudentLabels' => $outcome->taggedStudentLabels,
            'skippedStudentLabels' => $outcome->skippedStudentLabels,
            'filledStudentEmails' => $outcome->filledStudentEmails,
        ]);

        return $this->redirectToRoute('app_ufa_configuration_contract_import_result');
    }

    #[Route(path: '/ufa/configuration/contract-import/result', name: 'app_ufa_configuration_contract_import_result', methods: ['GET'])]
    public function result(Request $request): Response
    {
        $session = $request->getSession();
        $stored = $session->get(self::OUTCOME_SESSION_KEY);

        if (!\is_array($stored)) {
            return $this->redirectToRoute('app_ufa_configuration_contract_import');
        }

        // Read once: a refresh (or a bookmark) must not keep showing a report of an import that
        // happened at some point in the past as if it had just run.
        $session->remove(self::OUTCOME_SESSION_KEY);
        $session->remove(self::FILE_SESSION_KEY);

        $labels = static fn (mixed $value): array => array_values(array_filter(\is_array($value) ? $value : [], \is_string(...)));

        return $this->render('ufa/contract_import/result.html.twig', [
            'fileName' => \is_string($stored['fileName'] ?? null) ? $stored['fileName'] : '',
            'outcome' => new ImportOutcome(
                \is_int($stored['createdAlternances'] ?? null) ? $stored['createdAlternances'] : 0,
                $labels($stored['createdEnterpriseNames'] ?? null),
                $labels($stored['createdTutorLabels'] ?? null),
                $labels($stored['taggedStudentLabels'] ?? null),
                $labels($stored['skippedStudentLabels'] ?? null),
                $labels($stored['filledStudentEmails'] ?? null),
            ),
        ]);
    }

    /** Null means "nothing (usable) in the session" - the caller sends the operator back to step ①. */
    private function analyzeSessionRows(Request $request, ImportAnalyzer $analyzer, TranslatorInterface $translator): ?ImportAnalysis
    {
        $session = $request->getSession();
        $stored = $session->get(self::ROWS_SESSION_KEY);

        if (!\is_array($stored) || [] === $stored) {
            $this->addFlash('warning', 'ufaContractImportSessionExpiredFlashMessage');

            return null;
        }

        $rows = array_values(array_map(
            static fn (mixed $row): ContractRow => ContractRow::fromArray(\is_array($row) ? $row : []),
            $stored,
        ));

        $fileName = $session->get(self::FILE_SESSION_KEY);

        try {
            return $analyzer->analyze($rows, \is_string($fileName) ? $fileName : '');
        } catch (ImportFileException $exception) {
            $session->remove(self::ROWS_SESSION_KEY);
            $this->addFlash('danger', $translator->trans($exception->getMessageKey(), $exception->getParameters()));

            return null;
        }
    }
}
