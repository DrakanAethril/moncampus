<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Form\QuizImportType;
use App\Form\QuizTemplateSettingsType;
use App\Form\ZoneImportType;
use App\Service\FormValue;
use App\Service\KahootXlsxImporter;
use App\Service\MatchingExampleCatalog;
use App\Service\MatchingJsonImporter;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\ZoneExampleCatalog;
use App\Service\ZoneJsonImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Importer un CSV" - the second way into a quiz, next to QuizLibraryController::create()'s empty
 * one. Two steps on purpose: upload() reads the file into a session payload and preview() shows
 * what *would* be created, pre-filled and editable, so the teacher confirms before anything is
 * written. Nothing here persists until that confirmation form is submitted (see
 * App\Service\QuizCsvImporter's class docblock).
 *
 * The payload lives in the session rather than in a hidden field: a 48-question bank is ~30 KB of
 * JSON, which a form round-trip would carry on every keystroke-free re-render and which a proxy
 * could truncate. It is dropped as soon as the quiz is created, and whenever the teacher walks back
 * onto the upload screen.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class QuizImportController extends AbstractController
{
    private const string SESSION_KEY = 'quiz_csv_import';

    // Which prompt/examples the interactive screen shows. Not the payload's own `format` key (that
    // one says which importer produced a session payload) - these two only name the screen's tabs.
    private const string FAMILY_ZONES = 'zones';
    private const string FAMILY_MATCHING = 'apparier';

    // Shown on the documentation screen *and* served by the download link, so the example a teacher
    // reads is byte-for-byte the one they get - one covering row per supported question type.
    private const string EXAMPLE_CSV = <<<'CSV'
        "sequence";"seance";"referentiel";"type";"difficulte";"enonce";"reponse_1";"reponse_2";"reponse_3";"reponse_4";"bonnes";"points";"explication"
        "Principales failles web";"Les injections SQL";"B3.5";"qcm";"facile";"Quelle est la parade de référence contre les injections SQL ?";"Les requêtes préparées avec paramètres liés";"Échapper les apostrophes";"Masquer les erreurs SQL";"Limiter la taille des champs";"1";"1";"La requête préparée sépare définitivement le code SQL des valeurs."
        "Principales failles web";"Les injections SQL";"B3.5";"qcm_multi";"moyen";"Quelles pratiques réduisent le risque d'injection ?";"Requêtes préparées";"Principe du moindre privilège";"Concaténer les variables dans la requête";"Valider les entrées";"1,2,4";"2";"Seule la concaténation reste dangereuse."
        "Principales failles web";"Les injections SQL";"B3.5";"vrai_faux";"facile";"Toute donnée provenant de l'utilisateur doit être considérée comme hostile.";"Vrai";"Faux";"";"";"1";"1";"C'est le principe fondateur : never trust user input."
        "Principales failles web";"Les injections SQL";"B3.5";"ordre";"moyen";"Remettez dans l'ordre les étapes d'une requête préparée.";"Exécuter la requête";"Préparer la requête";"Lire le résultat";"Lier les paramètres";"2,4,1,3";"2";"On prépare, on lie, on exécute, puis on lit."
        "Principales failles web";"Les injections SQL";"B3.5";"texte_a_trous";"moyen";"La méthode ... de PDO compile la requête, la méthode ... l'exécute.";"prepare|prepare()";"execute|execute()";"";"";"";"2";"Les deux méthodes forment le couple de base de PDO."
        CSV;

    /**
     * Two ways in, one screen: a CSV built for this app, or a Kahoot game report. They differ in
     * how the file is read and in nothing else - both end on the same session payload, the same
     * preview and the same confirmation (see App\Service\KahootXlsxImporter).
     */
    #[Route(path: '/library/quiz/import', name: 'app_library_quiz_import', methods: ['GET', 'POST'], defaults: ['source' => QuizImportType::SOURCE_CSV])]
    #[Route(path: '/library/quiz/import/kahoot', name: 'app_library_quiz_import_kahoot', methods: ['GET', 'POST'], defaults: ['source' => QuizImportType::SOURCE_KAHOOT])]
    public function upload(string $source, Request $request, QuizCsvImporter $importer, KahootXlsxImporter $kahootImporter, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(QuizImportType::class, null, ['source' => $source]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            // Coming back here means starting over - never leave a previous file's payload behind
            // for preview() to resurrect.
            $request->getSession()->remove(self::SESSION_KEY);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();

            try {
                $payload = QuizImportType::SOURCE_KAHOOT === $source
                    ? $kahootImporter->parse($file)
                    : $importer->parse($file);
                $request->getSession()->set(self::SESSION_KEY, $payload);

                return $this->redirectToRoute('app_library_quiz_import_preview');
            } catch (QuizCsvImportException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('library/quiz_import.html.twig', ['form' => $form, 'source' => $source]);
    }

    /**
     * "Import interactif (JSON)" - the third way in: paste the document a language model produced
     * from the copyable prompt shown alongside (étude 2026-08-11, extended to Apparier on
     * 2026-08-11). Ends on the same session payload and the same preview/confirmation as the CSV
     * and Kahoot routes.
     *
     * `?family=` chooses which prompt and which ready-made examples the screen shows - the paste
     * field itself accepts either format whatever the tab says, because the pasted document names
     * its own format and refusing it on the wrong tab would be a puzzle rather than a safeguard.
     * `?example=` preloads one of the ready-made documents.
     */
    #[Route(path: '/library/quiz/import/interactive', name: 'app_library_quiz_import_interactive', methods: ['GET', 'POST'])]
    public function uploadInteractive(Request $request, ZoneJsonImporter $zoneImporter, MatchingJsonImporter $matchingImporter, TranslatorInterface $translator): Response
    {
        $family = self::FAMILY_MATCHING === $request->query->get('family') ? self::FAMILY_MATCHING : self::FAMILY_ZONES;
        $example = (string) $request->query->get('example', '');
        $form = $this->createForm(ZoneImportType::class, [
            'json' => self::FAMILY_MATCHING === $family ? MatchingExampleCatalog::json($example) : ZoneExampleCatalog::json($example),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            // Coming back here means starting over, exactly like upload().
            $request->getSession()->remove(self::SESSION_KEY);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $json = FormValue::string($form, 'json');
            try {
                $payload = self::FAMILY_MATCHING === $this->familyOf($json, $family)
                    ? $matchingImporter->parse($json, $translator->trans('zoneImportPastedFileName'))
                    : $zoneImporter->parse($json, $translator->trans('zoneImportPastedFileName'));
                $request->getSession()->set(self::SESSION_KEY, $payload);

                return $this->redirectToRoute('app_library_quiz_import_preview');
            } catch (QuizCsvImportException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('library/quiz_import_interactive.html.twig', [
            'form' => $form,
            'family' => $family,
            'exampleLabels' => self::FAMILY_MATCHING === $family ? MatchingExampleCatalog::labels() : ZoneExampleCatalog::labels(),
        ]);
    }

    #[Route(path: '/library/quiz/import/preview', name: 'app_library_quiz_import_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request, EntityManagerInterface $entityManager, QuizCsvImporter $importer, ZoneJsonImporter $zoneImporter, MatchingJsonImporter $matchingImporter, TranslatorInterface $translator): Response
    {
        $payload = $request->getSession()->get(self::SESSION_KEY);
        // The interactive route reports a fully-unusable document on its own screen, so an empty
        // question list can only mean an expired/absent session here.
        $format = \is_array($payload) ? ($payload['format'] ?? null) : null;
        $isZones = 'zones' === $format;
        $isMatching = 'matching' === $format;
        if (!\is_array($payload) || [] === ($payload['questions'] ?? [])) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute($isZones || $isMatching ? 'app_library_quiz_import_interactive' : 'app_library_quiz_import');
        }

        $template = new QuizTemplate($this->currentUser());
        $template->setName($payload['name']);
        $template->setSubject($payload['subject']);
        $template->setDescription($payload['description']);
        $template->setCreatedBy($this->currentUser());
        // A freshly imported bank is usually smaller than the 20-question default draw, and a draw
        // larger than the bank is rejected at launch time - propose the whole bank instead.
        $template->setDefaultQuestionCount(min($template->getDefaultQuestionCount(), \count($payload['questions'])));

        $form = $this->createForm(QuizTemplateSettingsType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isZones) {
                $zoneImporter->appendQuestions($template, $payload['questions']);
            } elseif ($isMatching) {
                $matchingImporter->appendQuestions($template, $payload['questions']);
            } else {
                $importer->appendQuestions($template, $payload['questions']);
            }
            $template->setLastUpdatedBy($this->currentUser());
            $template->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->persist($template);
            $entityManager->flush();

            $request->getSession()->remove(self::SESSION_KEY);
            $this->addFlash('success', 'quizImportCreatedFlashMessage');

            return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
        }

        // Zones and apparier questions preview through real (transient, never persisted) entities:
        // their rendering partials work on QuizQuestionDefinition, not on the raw payload arrays -
        // which is exactly what makes this preview identical to the future passation.
        $previewQuestions = [];
        if ($isZones || $isMatching) {
            $previewTemplate = new QuizTemplate($this->currentUser());
            if ($isZones) {
                $zoneImporter->appendQuestions($previewTemplate, $payload['questions'], copyImages: false);
            } else {
                $matchingImporter->appendQuestions($previewTemplate, $payload['questions'], copyImages: false);
            }
            $previewQuestions = $previewTemplate->getQuestions()->toArray();
        }

        return $this->render('library/quiz_import_preview.html.twig', [
            'form' => $form,
            'payload' => $payload,
            'isZones' => $isZones,
            'isMatching' => $isMatching,
            'previewQuestions' => $previewQuestions,
            'typeLabels' => $this->labelsFor(QuestionType::cases(), $translator),
            'difficultyDots' => array_combine(
                array_map(static fn (QuestionDifficulty $case): string => $case->value, QuestionDifficulty::cases()),
                array_map(static fn (QuestionDifficulty $case): int => $case->dotCount(), QuestionDifficulty::cases()),
            ),
        ]);
    }

    #[Route(path: '/library/quiz/import/documentation', name: 'app_library_quiz_import_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->render('library/quiz_import_help.html.twig', [
            'exampleCsv' => self::EXAMPLE_CSV,
            'max_questions' => QuizCsvImporter::MAX_QUESTIONS,
        ]);
    }

    #[Route(path: '/library/quiz/import/example.csv', name: 'app_library_quiz_import_example', methods: ['GET'])]
    public function example(): Response
    {
        // Excel opens a CSV as the system's legacy code page unless the file announces itself with
        // a BOM, which turns every accent into mojibake before the teacher has typed anything. The
        // importer strips the BOM back off on the way in.
        $response = new Response("\xEF\xBB\xBF".self::EXAMPLE_CSV."\n");
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', 'exemple-quiz.csv'));

        return $response;
    }

    /**
     * Which importer should read this document. The pasted JSON names its own format, so a teacher
     * who opened the "zones" tab and pasted an apparier document still gets what they meant; only
     * a document that names neither falls back to the tab they are on, which is what makes the
     * "expected format" error message name the format they were looking at.
     */
    private function familyOf(string $json, string $fallbackFamily): string
    {
        $document = json_decode($json, true);
        $format = \is_array($document) && \is_scalar($document['format'] ?? null) ? (string) $document['format'] : null;

        return match ($format) {
            MatchingJsonImporter::FORMAT => self::FAMILY_MATCHING,
            ZoneJsonImporter::FORMAT => self::FAMILY_ZONES,
            default => $fallbackFamily,
        };
    }

    /**
     * @param list<QuestionType> $cases
     *
     * @return array<string, string> enum value => translated short badge label
     */
    private function labelsFor(array $cases, TranslatorInterface $translator): array
    {
        return array_combine(
            array_map(static fn (QuestionType $case): string => $case->value, $cases),
            array_map(static fn (QuestionType $case): string => $translator->trans($case->shortLabelKey()), $cases),
        );
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
