<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Form\QuizImportType;
use App\Form\QuizTemplateSettingsType;
use App\Form\ZoneImportType;
use App\Repository\QuizTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\QuizTemplateVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\FormValue;
use App\Service\InteractiveQuizImporterRegistry;
use App\Service\KahootXlsxImporter;
use App\Service\MixedJsonImporter;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportImages;
use App\Service\QuizImportImageValidator;
use App\Service\QuizPromptCatalog;
use App\Service\QuizQuestionCompleteness;
use App\Service\QuizSourceContext;
use App\Service\QuizSourceContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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

    private const string DESTINATION_NEW = 'new';

    private const string DESTINATION_EXISTING = 'existing';

    /**
     * The first question of the paste screen, and the same one the séquence assistant asks at its step
     * 1: do you already have the questions, or are they to be generated from the course?
     *
     * It lives in the query string rather than in JS state so that a bookmark, a browser Back and the
     * "Quiz" menu of a séquence all name the same screen - that menu's three entries are three links
     * carrying a mode, which is what makes the menu itself the question.
     */
    private const string MODE_TRANSPOSE = 'transpose';

    private const string MODE_GENERATE = 'generate';

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
     * "Coller un document" - the way in for questions a language model produced from the prompt the
     * screen builds alongside (étude 2026-08-11, one screen for the twelve types since 2026-08-12).
     * Ends on the same session payload and the same preview/confirmation as the CSV and Kahoot
     * routes.
     *
     * There are no per-family tabs any more: the prompt is assembled from the types the teacher
     * ticks, and a pasted document names its own format, so there was nothing left for a tab to
     * choose. The four older formats stay readable (the application emitted them; refusing them
     * would break a round trip it produced itself) - only the prompt and the export speak
     * "moncampus-quiz/1". `?example=` preloads one of the ready-made documents.
     */
    #[Route(path: '/library/quiz/import/interactive', name: 'app_library_quiz_import_interactive', methods: ['GET', 'POST'])]
    public function uploadInteractive(
        Request $request,
        InteractiveQuizImporterRegistry $registry,
        MixedJsonImporter $mixed,
        QuizImportImages $images,
        QuizSourceContextFactory $contextFactory,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
        TranslatorInterface $translator,
    ): Response {
        // "This import is being made from that course": null when the teacher came in from the quiz
        // library, which is the ordinary case and asks for no course at all.
        $context = $this->sourceContext($request, $contextFactory, $sequenceRepository, $seanceRepository);
        $mode = $this->mode($request, $context);
        // "Concours live" pre-ticks the existing filter rather than being a mode of its own: seven of
        // the twelve types are excluded from a live contest and nothing used to say so at generation
        // time (App\Enum\QuestionType::isAvailableInLiveContest()).
        $liveOnly = $request->query->getBoolean('live');

        $form = $this->createForm(ZoneImportType::class, [
            'json' => $mixed->exampleJson((string) $request->query->get('example', '')),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            // Coming back here means starting over, exactly like upload(). The deposited images are
            // deliberately *not* dropped: they were put there for the document about to be pasted.
            $request->getSession()->remove(self::SESSION_KEY);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $json = FormValue::string($form, 'json');
            try {
                $payload = $registry->forDocument($json, $mixed->family())
                    ->parse($json, $translator->trans('zoneImportPastedFileName'));
                $request->getSession()->set(self::SESSION_KEY, $payload);

                return $this->redirectToRoute('app_library_quiz_import_preview');
            } catch (QuizCsvImportException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('library/quiz_import_interactive.html.twig', [
            'form' => $form,
            'exampleLabels' => $mixed->exampleLabels(),
            'depositedImages' => $images->batch()->all(),
            // The prompt's own text, out of App\Service\QuizPromptCatalog rather than out of the
            // template it used to sit in - the séquence import assistant makes it three prompt
            // screens, and twelve fragments inline in a Twig file is where they start to diverge.
            'promptEnvelope' => QuizPromptCatalog::envelope(),
            // The mode decides the closing, here rather than in the browser: two closings shipped to
            // the page and switched by JS would be two texts to keep in step for no gain, since the
            // mode is a page of its own already.
            'promptClosing' => self::MODE_TRANSPOSE === $mode ? QuizPromptCatalog::transposeClosing() : QuizPromptCatalog::closing(),
            'promptFragments' => QuizPromptCatalog::fragments(),
            // The selector's own rows: every type is tickable, and the "compatibles concours live"
            // filter is a method call rather than a list to keep in step
            // (App\Enum\QuestionType::isAvailableInLiveContest()).
            'promptTypes' => array_map(static fn (QuestionType $case): array => [
                'value' => $case->value,
                'label' => $translator->trans($case->labelKey()),
                'live' => $case->isAvailableInLiveContest(),
            ], QuestionType::cases()),
            'mode' => $mode,
            'modeTranspose' => self::MODE_TRANSPOSE,
            'modeGenerate' => self::MODE_GENERATE,
            'liveOnly' => $liveOnly,
            'modeParams' => $this->scopeParams($request),
            'sourceContext' => null === $context ? null : [
                'scope' => $context->scope->value,
                'title' => $context->title,
                'sentence' => $context->scopeSentence(),
                'hasObjectives' => $context->hasObjectives(),
                'hasPhases' => $context->hasPhases(),
                'isEmpty' => $context->isEmpty(),
                'max' => QuizPromptCatalog::MAX_CONTEXT_CHARACTERS,
                // The four combinations, assembled by App\Service\QuizSourceContext and handed over
                // whole. The browser picks one and counts it; it never assembles the block itself,
                // which is what keeps the figure on screen equal to the text that is sent.
                'variants' => [
                    '11' => $context->text(withObjectives: true, withPhases: true),
                    '10' => $context->text(withObjectives: true, withPhases: false),
                    '01' => $context->text(withObjectives: false, withPhases: true),
                    '00' => '',
                ],
            ],
        ]);
    }

    /**
     * The course this import is being made from, or null.
     *
     * Access is checked on the séquence in both cases, including when a séance was named: a séance has
     * no voter of its own, and its séquence is what owns the teacher's claim to it
     * (App\Security\Voter\SequenceTemplateVoter).
     */
    private function sourceContext(
        Request $request,
        QuizSourceContextFactory $factory,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): ?QuizSourceContext {
        $seanceId = $request->query->getInt('seance');
        if ($seanceId > 0) {
            $seance = $seanceRepository->find($seanceId);
            if (!$seance instanceof SeanceTemplate || !$seance->getSequenceTemplate() instanceof SequenceTemplate) {
                throw $this->createNotFoundException();
            }
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $seance->getSequenceTemplate());

            return $factory->forSeance($seance);
        }

        $sequenceId = $request->query->getInt('sequence');
        if ($sequenceId > 0) {
            $sequence = $sequenceRepository->find($sequenceId);
            if (!$sequence instanceof SequenceTemplate) {
                throw $this->createNotFoundException();
            }
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);

            return $factory->forSequence($sequence);
        }

        return null;
    }

    /**
     * Transposing is the default, because it is the answer that needs no course - a teacher who
     * reached this screen from the quiz library has a document in hand. Arriving *from* a séquence or a
     * séance flips it: that trip was made to get questions out of the course.
     */
    private function mode(Request $request, ?QuizSourceContext $context): string
    {
        $asked = (string) $request->query->get('mode', '');
        if (\in_array($asked, [self::MODE_TRANSPOSE, self::MODE_GENERATE], true)) {
            return $asked;
        }

        return null === $context ? self::MODE_TRANSPOSE : self::MODE_GENERATE;
    }

    /**
     * What the two mode cards must carry over when they link to each other: the course and the live
     * filter survive a change of mode, the pasted document does not exist yet.
     *
     * @return array<string, int|string>
     */
    private function scopeParams(Request $request): array
    {
        $params = [];
        foreach (['sequence', 'seance'] as $key) {
            if ($request->query->getInt($key) > 0) {
                $params[$key] = $request->query->getInt($key);
            }
        }
        if ($request->query->getBoolean('live')) {
            $params['live'] = 1;
        }

        return $params;
    }

    /**
     * Deposits an image of the batch and hands back the short reference the prompt will carry -
     * img1, img2… Nothing is published: the model sees the file because the teacher attaches it to
     * their conversation, the key only says *which* one (App\Service\QuizImportImageBatch).
     */
    #[Route(path: '/library/quiz/import/images', name: 'app_library_quiz_import_image_add', methods: ['POST'])]
    public function addImage(Request $request, QuizImportImages $images, QuizImportImageValidator $validator, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('quiz_import_images', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('image');
        $error = $file instanceof UploadedFile ? $validator->validate($file) : 'quizImportImageMissingError';
        if (null !== $error) {
            $this->addFlash('warning', $error);
        } elseif ($file instanceof UploadedFile) {
            $this->addFlash('success', $translator->trans('quizImportImageAddedFlashTemplate', ['%ref%' => $images->add($file)]));
        }

        return $this->redirectToRoute('app_library_quiz_import_interactive');
    }

    #[Route(path: '/library/quiz/import/images/remove', name: 'app_library_quiz_import_image_remove', methods: ['POST'])]
    public function removeImage(Request $request, QuizImportImages $images): Response
    {
        if (!$this->isCsrfTokenValid('quiz_import_images', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $images->remove((string) $request->request->get('ref'));

        return $this->redirectToRoute('app_library_quiz_import_interactive');
    }

    /**
     * The verification step, and the one thing it decides: where the questions land. A bank is built
     * in several goes, so "ajouter à un quiz existant" sits above everything else on this screen -
     * appendQuestions() has always accepted any template, the hard-coded `new QuizTemplate()` here
     * was the only thing in the way.
     */
    #[Route(path: '/library/quiz/import/preview', name: 'app_library_quiz_import_preview', methods: ['GET', 'POST'])]
    public function preview(
        Request $request,
        EntityManagerInterface $entityManager,
        QuizCsvImporter $importer,
        InteractiveQuizImporterRegistry $registry,
        QuizTemplateRepository $templateRepository,
        QuizImportImages $images,
        QuizQuestionCompleteness $completeness,
        TranslatorInterface $translator,
    ): Response {
        $payload = $request->getSession()->get(self::SESSION_KEY);
        // Which family produced this payload, or null for the CSV/Kahoot route - the interactive
        // route reports a fully-unusable document on its own screen, so an empty question list can
        // only mean an expired/absent session here.
        $interactive = $registry->forPayloadFormat(\is_array($payload) ? ($payload['format'] ?? null) : null);
        if (!\is_array($payload) || [] === ($payload['questions'] ?? [])) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute(null !== $interactive ? 'app_library_quiz_import_interactive' : 'app_library_quiz_import');
        }

        $template = new QuizTemplate($this->currentUser());
        $template->setName($payload['name']);
        $template->setSubject($payload['subject']);
        $template->setDescription($payload['description']);
        $template->setCreatedBy($this->currentUser());
        // A freshly imported bank is usually smaller than the 20-question default draw, and a draw
        // larger than the bank is rejected at launch time - propose the whole bank instead. Only on
        // a new quiz: on an existing one this would overwrite a choice the teacher made.
        $template->setDefaultQuestionCount(min($template->getDefaultQuestionCount(), \count($payload['questions'])));

        $existingTemplates = $templateRepository->findForTeacher($this->currentUser());
        $form = $this->createForm(QuizTemplateSettingsType::class, $template, [
            // The identity fields describe a quiz that is not going to exist when the teacher adds
            // to an existing one; validating them would refuse the import over a field the screen
            // has folded away.
            'validation_groups' => static fn (FormInterface $form): array => self::DESTINATION_EXISTING === $form->get('destination')->getData() ? [] : ['Default'],
        ]);
        $form->add('destination', ChoiceType::class, [
            'mapped' => false,
            'expanded' => true,
            'data' => self::DESTINATION_NEW,
            'choices' => [
                'quizImportDestinationNewLabel' => self::DESTINATION_NEW,
                'quizImportDestinationExistingLabel' => self::DESTINATION_EXISTING,
            ],
            'label' => false,
        ]);
        $form->add('targetTemplate', EntityType::class, [
            'mapped' => false,
            'required' => false,
            'class' => QuizTemplate::class,
            'choices' => $existingTemplates,
            'choice_label' => static fn (QuizTemplate $one): string => (string) $one->getName(),
            'placeholder' => 'quizImportDestinationPlaceholder',
            'label' => 'quizImportDestinationSelectLabel',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $target = $this->resolveDestination($form, $template, $translator);
            if (null !== $target) {
                if ($target !== $template) {
                    $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $target);
                }

                if (null !== $interactive) {
                    $interactive->appendQuestions($target, $payload['questions']);
                } else {
                    $importer->appendQuestions($target, $payload['questions']);
                }
                $target->setLastUpdatedBy($this->currentUser());
                $target->setLastUpdatedDate(new \DateTimeImmutable());
                $entityManager->persist($target);
                $entityManager->flush();

                $request->getSession()->remove(self::SESSION_KEY);
                // The batch is over: the questions that needed one of these images carry their own
                // copy by now, so nothing here is worth keeping in the bucket.
                $images->clear();
                $this->addFlash('success', $target === $template ? 'quizImportCreatedFlashMessage' : 'quizImportAppendedFlashMessage');

                return $this->redirectToRoute('app_library_quiz_questions', ['id' => $target->getId()]);
            }
        }

        // The preview builds real (transient, never persisted) entities and renders them through the
        // partials the passation itself uses - which is what makes it show the question the student
        // will get, rather than a description of it. It is also what tells apart a question that
        // found its deposited image from one that will wait for one.
        $previewTemplate = new QuizTemplate($this->currentUser());
        if (null !== $interactive) {
            $interactive->appendQuestions($previewTemplate, $payload['questions'], copyImages: false);
        } else {
            $importer->appendQuestions($previewTemplate, $payload['questions']);
        }
        $previewQuestions = $previewTemplate->getQuestions()->toArray();

        return $this->render('library/quiz_import_preview.html.twig', [
            'form' => $form,
            'payload' => $payload,
            'family' => $interactive?->family(),
            'previewQuestions' => $previewQuestions,
            'incompleteCount' => $completeness->countIncomplete($previewQuestions),
            'gaps' => array_map($completeness->gapOf(...), $previewQuestions),
            'existingTemplates' => $existingTemplates,
            'typeLabels' => $this->labelsFor(QuestionType::cases(), $translator),
            'difficultyDots' => array_combine(
                array_map(static fn (QuestionDifficulty $case): string => $case->value, QuestionDifficulty::cases()),
                array_map(static fn (QuestionDifficulty $case): int => $case->dotCount(), QuestionDifficulty::cases()),
            ),
        ]);
    }

    /**
     * The quiz the questions are about to be written into: the transient one for "nouveau quiz", the
     * chosen one for "ajouter à un quiz existant". Null when the teacher asked for the second and
     * left the list on its placeholder, which the screen reports rather than silently creating a
     * quiz they did not ask for.
     */
    private function resolveDestination(FormInterface $form, QuizTemplate $newTemplate, TranslatorInterface $translator): ?QuizTemplate
    {
        if (self::DESTINATION_EXISTING !== $form->get('destination')->getData()) {
            return $newTemplate;
        }

        $target = $form->get('targetTemplate')->getData();
        if ($target instanceof QuizTemplate) {
            return $target;
        }

        $form->get('targetTemplate')->addError(new FormError($translator->trans('quizImportDestinationMissingError')));

        return null;
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
