<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Enum\QuizSourceScope;
use App\Form\QuizImportType;
use App\Form\QuizTemplateSettingsType;
use App\Repository\QuizTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\QuizTemplateVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\InteractiveQuizImporterRegistry;
use App\Service\KahootXlsxImporter;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportImages;
use App\Service\QuizImportImageValidator;
use App\Service\QuizImportSession;
use App\Service\QuizQuestionCompleteness;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
    private const string DESTINATION_NEW = 'new';

    private const string DESTINATION_EXISTING = 'existing';

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
            // for preview() to resurrect. The course a *pasted* document came from goes with it: a
            // spreadsheet uploaded afterwards has nothing to do with that séance, and an attachment
            // offered on its confirmation screen would be a link the teacher never asked for.
            $request->getSession()->remove(QuizImportSession::PAYLOAD_KEY);
            $request->getSession()->remove(QuizImportSession::SOURCE_KEY);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();

            try {
                $payload = QuizImportType::SOURCE_KAHOOT === $source
                    ? $kahootImporter->parse($file)
                    : $importer->parse($file);
                $request->getSession()->set(QuizImportSession::PAYLOAD_KEY, $payload);

                return $this->redirectToRoute('app_library_quiz_import_preview');
            } catch (QuizCsvImportException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('library/quiz_import.html.twig', ['form' => $form, 'source' => $source]);
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

        return $this->redirectToRoute('app_library_quiz_assistant_prompt');
    }

    #[Route(path: '/library/quiz/import/images/remove', name: 'app_library_quiz_import_image_remove', methods: ['POST'])]
    public function removeImage(Request $request, QuizImportImages $images): Response
    {
        if (!$this->isCsrfTokenValid('quiz_import_images', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $images->remove((string) $request->request->get('ref'));

        return $this->redirectToRoute('app_library_quiz_assistant_prompt');
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
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
        TranslatorInterface $translator,
    ): Response {
        $payload = $request->getSession()->get(QuizImportSession::PAYLOAD_KEY);
        // Which family produced this payload, or null for the CSV/Kahoot route - the interactive
        // route reports a fully-unusable document on its own screen, so an empty question list can
        // only mean an expired/absent session here.
        $interactive = $registry->forPayloadFormat(\is_array($payload) ? ($payload['format'] ?? null) : null);
        if (!\is_array($payload) || [] === ($payload['questions'] ?? [])) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute(null !== $interactive ? 'app_library_quiz_assistant_paste' : 'app_library_quiz_import');
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
        // The course this import came from, and the extra destination it earns: « rattacher à la séance
        // … », pre-checked. Pre-checked and not forced - a quiz generated from a séance is *usually*
        // for that séance, and a teacher who is building a general bank must be able to say no in one
        // click. Absent entirely when the import did not come from a course.
        $attachTo = $this->attachmentTarget($request, $sequenceRepository, $seanceRepository);
        if (null !== $attachTo) {
            $form->add('attach', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'data' => true,
                'label' => 'quizImportAttachLabel',
            ]);
        }
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
                if (null !== $attachTo && true === $form->get('attach')->getData()) {
                    // Attached, never moved: the quiz stays in the teacher's library, which is its home
                    // (App\Entity\QuizTemplate::$seanceTemplates). Adding a link it already has is a
                    // no-op, so appending twice to the same séance cannot duplicate a row.
                    if ($attachTo instanceof SeanceTemplate) {
                        $target->addSeanceTemplate($attachTo);
                    } else {
                        $target->addSequenceTemplate($attachTo);
                    }
                }

                $target->setLastUpdatedBy($this->currentUser());
                $target->setLastUpdatedDate(new \DateTimeImmutable());
                $entityManager->persist($target);
                $entityManager->flush();

                $request->getSession()->remove(QuizImportSession::PAYLOAD_KEY);
                $request->getSession()->remove(QuizImportSession::SOURCE_KEY);
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
            // What the extra destination is about to attach to, named so the checkbox can say it.
            'attachTo' => null === $attachTo ? null : [
                'scope' => $attachTo instanceof SeanceTemplate ? QuizSourceScope::Seance->value : QuizSourceScope::Sequence->value,
                'title' => (string) $attachTo->getTitre(),
            ],
            'typeLabels' => $this->labelsFor(QuestionType::cases(), $translator),
            'difficultyDots' => array_combine(
                array_map(static fn (QuestionDifficulty $case): string => $case->value, QuestionDifficulty::cases()),
                array_map(static fn (QuestionDifficulty $case): int => $case->dotCount(), QuestionDifficulty::cases()),
            ),
        ]);
    }

    /**
     * The séance or séquence this import may be attached to on confirmation, read off the session key
     * the paste screen wrote (QuizImportSession::SOURCE_KEY), or null.
     *
     * Access is re-checked here rather than trusted from one request earlier: the session is the
     * teacher's own, but a séquence can change hands - or be deleted - between the paste and the
     * confirmation, and a stale id must not become a link.
     */
    private function attachmentTarget(
        Request $request,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): SeanceTemplate|SequenceTemplate|null {
        $source = $request->getSession()->get(QuizImportSession::SOURCE_KEY);
        if (!\is_array($source)) {
            return null;
        }

        $seanceId = \is_scalar($source['seance'] ?? null) ? (int) $source['seance'] : 0;
        if ($seanceId > 0) {
            $seance = $seanceRepository->find($seanceId);
            $sequence = $seance?->getSequenceTemplate();

            return $seance instanceof SeanceTemplate && $sequence instanceof SequenceTemplate
                && $this->isGranted(SequenceTemplateVoter::EDIT, $sequence) ? $seance : null;
        }

        $sequenceId = \is_scalar($source['sequence'] ?? null) ? (int) $source['sequence'] : 0;
        if ($sequenceId > 0) {
            $sequence = $sequenceRepository->find($sequenceId);

            return $sequence instanceof SequenceTemplate && $this->isGranted(SequenceTemplateVoter::EDIT, $sequence)
                ? $sequence : null;
        }

        return null;
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
