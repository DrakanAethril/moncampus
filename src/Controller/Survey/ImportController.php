<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Attribute\RequiresFeature;
use App\Entity\SurveyFolder;
use App\Entity\SurveyTemplate;
use App\Enum\Feature;
use App\Enum\SurveyAssistantPath;
use App\Enum\SurveyQuestionType;
use App\Form\Survey\SurveyTemplateType;
use App\Repository\SurveyFolderRepository;
use App\Repository\SurveyTemplateRepository;
use App\Security\Voter\SurveyVoter;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\Survey\SurveyAssistantRequest;
use App\Service\Survey\SurveyAssistantState;
use App\Service\Survey\SurveyImportException;
use App\Service\Survey\SurveyImportSession;
use App\Service\Survey\SurveyJsonImporter;
use App\Service\Survey\SurveyPromptCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Un questionnaire, d'où vient-il ? » - the assistant that walks an author from that question to a
 * survey model in their library, through a conversation with a language model held *outside* the
 * application.
 *
 * ① Que voulez-vous faire ? · ② Le prompt · ③ Coller · ④ Vérifier. It is deliberately the quiz
 * assistant's parcours, step for step (App\Controller\QuizImportAssistantController): the same
 * teachers use both, and an import that asked its four questions in another order would be a second
 * thing to learn for no gain.
 *
 * Three differences, each of them a consequence of what a survey is rather than a simplification:
 *
 *  - **Two doors at step 1, not four.** No « depuis une séquence » (a survey is not about a lesson's
 *    content) and no file card (there is no CSV channel here) - see App\Enum\SurveyAssistantPath.
 *  - **One reader, no registry.** The five types differ only by whether they carry propositions, so
 *    App\Service\Survey\SurveyJsonImporter owns the whole format.
 *  - **One controller, not two.** The quiz splits its step 4 off because that screen is shared with
 *    the CSV and Kahoot routes; here nothing is shared, and a second class would only move the
 *    session keys further from the code that writes them.
 *
 * **Nothing is written before step 4 is confirmed.** The document travels in the session, and the
 * verification screen builds transient entities to show what *would* be created.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Surveys)]
class ImportController extends AbstractController
{
    use SurveyTabTrait;

    private const string DESTINATION_NEW = 'new';

    private const string DESTINATION_EXISTING = 'existing';

    /**
     * Translation keys - _wizard_steps.html.twig prints its labels as it gets them, so they are
     * translated on the way in rather than in the partial.
     *
     * @var list<string>
     */
    private const array STEP_LABELS = [
        'surveyAssistantStep1Label', 'surveyAssistantStep2Label', 'surveyAssistantStep3Label', 'surveyAssistantStep4Label',
    ];

    /**
     * Step 1 - « Que voulez-vous faire ? ». Two cards, one question.
     *
     * Each card is a form rather than a link: the answer is written into the session before anything
     * else happens, so the assistant is resumable from the very first click, and a GET that mutates
     * state is a Back button away from a surprise.
     */
    #[Route(path: '/surveys/import', name: 'app_survey_import', methods: ['GET', 'POST'])]
    public function start(Request $request, TranslatorInterface $translator): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'survey_import_start');

            $path = SurveyAssistantPath::tryFrom((string) $request->request->get('path'));
            if (null === $path) {
                return $this->redirectToRoute('app_survey_import');
            }

            $this->save($request, new SurveyAssistantState($path, $this->state($request)->request));

            return $this->redirectToRoute('app_survey_import_prompt');
        }

        // The front door is where the folder the author came from is recorded, and nowhere else -
        // null included, which is what clears the folder an abandoned import left behind. « Importer »
        // clicked inside a folder files the model there, exactly like « + Nouveau sondage ».
        $request->getSession()->set(SurveyImportSession::FOLDER_KEY, QueryValue::nullableInt($request, 'folder'));

        return $this->render('survey/import_start.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 1,
            // Coming back to the front door does not throw away a conversion in progress - step 2
            // ends outside the application, sometimes for a while.
            'resumeRoute' => $this->resumeRoute($request),
        ]);
    }

    /**
     * Step 2 - « Voici comment demander votre questionnaire ». Instructions, not a form: everything
     * on it feeds one block of text, and the only thing the author does is copy it.
     *
     * The five type fragments are ticked here, and « Ma demande » is typed next to the prompt it
     * fills in - typing it far from the text it produces is typing it blind.
     */
    #[Route(path: '/surveys/import/prompt', name: 'app_survey_import_prompt', methods: ['GET', 'POST'])]
    public function prompt(Request $request, TranslatorInterface $translator): Response
    {
        $state = $this->state($request);
        if (null === $state->path) {
            return $this->redirectToRoute('app_survey_import');
        }

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'survey_import_prompt');
            $this->save($request, $state->withRequest(SurveyAssistantRequest::fromArray(PostValue::all($request, 'demand'))));

            return $this->redirectToRoute('app_survey_import_paste');
        }

        return $this->render('survey/import_prompt.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 2,
            'path' => $state->path,
            'pathSubject' => SurveyAssistantPath::Subject,
            'pathTranspose' => SurveyAssistantPath::Transpose,
            'demand' => $state->request->toArray(),
            'maxQuestionCount' => SurveyAssistantRequest::MAX_QUESTION_COUNT,
            'promptEnvelope' => SurveyPromptCatalog::envelope(),
            // The closing is decided here and not in the browser: the path is a step of its own, so
            // two closings shipped to the page and switched by JS would be two texts to keep in step
            // for no gain at all.
            'promptClosing' => $state->path->generates()
                ? SurveyPromptCatalog::typeChoice()
                : SurveyPromptCatalog::transposeClosing(),
            // Empty on the transposition path: it states no subject and no count - the author's own
            // questionnaire holds all of it, and announcing a number would invite a model to write
            // the questions it is missing.
            'promptDemandTemplate' => $state->path->generates() ? SurveyPromptCatalog::demandTemplate() : '',
            'promptDemandPlaceholders' => SurveyPromptCatalog::demandPlaceholders(),
            'promptDemandExtraHeading' => SurveyPromptCatalog::EXTRA_HEADING,
            'promptFragments' => SurveyPromptCatalog::fragments(),
            'promptTypes' => array_map(static fn (SurveyQuestionType $case): array => [
                'value' => $case->value,
                'label' => $translator->trans($case->labelKey()),
            ], SurveyQuestionType::forEditor()),
        ]);
    }

    /**
     * Step 3 - « Collez ce que l'IA vous a rendu ». One box, full frame, and a worked example.
     *
     * A document that will not parse is answered **422** and not 200: a POST rendered 200 is thrown
     * away by Turbo, and the author would watch their error message never appear.
     */
    #[Route(path: '/surveys/import/paste', name: 'app_survey_import_paste', methods: ['GET', 'POST'])]
    public function paste(Request $request, SurveyJsonImporter $importer, TranslatorInterface $translator): Response
    {
        $state = $this->state($request);
        if (null === $state->path) {
            return $this->redirectToRoute('app_survey_import');
        }

        $json = '';
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'survey_import_paste');
            $json = trim((string) $request->request->get('json'));

            try {
                $request->getSession()->set(
                    SurveyImportSession::PAYLOAD_KEY,
                    $importer->parse($json, $translator->trans('zoneImportPastedFileName')),
                );

                return $this->redirectToRoute('app_survey_import_preview');
            } catch (SurveyImportException $exception) {
                $error = $translator->trans($exception->getMessageKey(), $exception->getParameters());
            }
        }

        return $this->render('survey/import_paste.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 3,
            'json' => '' !== $json ? $json : ($request->query->getBoolean('example') ? $importer->exampleJson() : ''),
            'error' => $error,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Step 4 - the verification, and the one thing it decides: where the questions land. A
     * questionnaire is built in several goes, so « ajouter à un sondage existant » sits above
     * everything else on this screen.
     *
     * What is shown is built from real transient entities, so the author reads the questions the way
     * the editor will show them rather than a description of the document.
     */
    #[Route(path: '/surveys/import/preview', name: 'app_survey_import_preview', methods: ['GET', 'POST'])]
    public function preview(
        Request $request,
        EntityManagerInterface $entityManager,
        SurveyJsonImporter $importer,
        SurveyTemplateRepository $templateRepository,
        SurveyFolderRepository $folders,
        TranslatorInterface $translator,
    ): Response {
        $payload = $request->getSession()->get(SurveyImportSession::PAYLOAD_KEY);
        if (!\is_array($payload) || [] === ($payload['questions'] ?? [])) {
            $this->addFlash('warning', 'surveyImportExpiredFlashMessage');

            return $this->redirectToRoute('app_survey_import_paste');
        }

        /** @var list<array<string, mixed>> $questions */
        $questions = $payload['questions'];

        $template = new SurveyTemplate();
        $template->setOwner($this->currentUser());
        // Where the import was started from, so a model produced inside a folder lands in it rather
        // than at the root the author would then have to move it from.
        $template->setFolder($this->rememberedFolder($request, $folders));
        $template->setName(\is_string($payload['name'] ?? null) ? $payload['name'] : $translator->trans('surveyImportDefaultTemplateName'));
        $template->setSubject(\is_string($payload['subject'] ?? null) ? $payload['subject'] : null);
        $template->setDescription(\is_string($payload['description'] ?? null) ? $payload['description'] : null);

        $existingTemplates = $templateRepository->findForOwner($this->currentUser());
        $form = $this->createForm(SurveyTemplateType::class, $template, [
            // The identity fields describe a model that is not going to exist when the author adds to
            // an existing one; validating them would refuse the import over a field the screen has
            // folded away.
            'validation_groups' => static fn (FormInterface $form): array => self::DESTINATION_EXISTING === $form->get('destination')->getData() ? [] : ['Default'],
        ]);
        $form->add('destination', ChoiceType::class, [
            'mapped' => false,
            'expanded' => true,
            'data' => self::DESTINATION_NEW,
            'choices' => [
                'surveyImportDestinationNewLabel' => self::DESTINATION_NEW,
                'surveyImportDestinationExistingLabel' => self::DESTINATION_EXISTING,
            ],
            'label' => false,
        ]);
        $form->add('targetTemplate', EntityType::class, [
            'mapped' => false,
            'required' => false,
            'class' => SurveyTemplate::class,
            'choices' => $existingTemplates,
            'choice_label' => static fn (SurveyTemplate $one): string => $one->getName(),
            'placeholder' => 'surveyImportDestinationPlaceholder',
            'label' => 'surveyImportDestinationSelectLabel',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $target = $this->resolveDestination($form, $template, $translator);
            if (null !== $target) {
                // The owner alone edits a model - EDIT carries no staff bypass, and a model reached
                // through the select is re-checked rather than trusted from the list that built it.
                $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $target);

                $importer->appendQuestions($target, $questions);
                $target->touch();
                $entityManager->persist($target);
                $entityManager->flush();

                $request->getSession()->remove(SurveyImportSession::PAYLOAD_KEY);
                $request->getSession()->remove(SurveyImportSession::FOLDER_KEY);
                $request->getSession()->remove(SurveyImportSession::ASSISTANT_KEY);
                $this->addFlash('success', $target === $template ? 'surveyImportCreatedFlashMessage' : 'surveyImportAppendedFlashMessage');

                return $this->redirectToRoute('app_survey_template_edit', ['id' => $target->getId()]);
            }
        }

        // What the payload would become: the very entities appendQuestions() writes, on a model
        // nobody will save. One reader for the preview and for the confirmation is what keeps the
        // screen honest.
        $preview = new SurveyTemplate();
        $preview->setOwner($this->currentUser());
        $importer->appendQuestions($preview, $questions);

        return $this->render('survey/import_preview.html.twig', [
            'form' => $form,
            'payload' => $payload,
            'previewQuestions' => $preview->getQuestions()->toArray(),
            'answerableCount' => \count($preview->answerableQuestions()),
            'existingTemplates' => $existingTemplates,
        ]);
    }

    /**
     * The model the questions are about to be written into: the transient one for « nouveau
     * sondage », the chosen one for « ajouter à un sondage existant ». Null when the author asked
     * for the second and left the list on its placeholder, which the screen reports rather than
     * silently creating a model they did not ask for.
     */
    private function resolveDestination(FormInterface $form, SurveyTemplate $newTemplate, TranslatorInterface $translator): ?SurveyTemplate
    {
        if (self::DESTINATION_EXISTING !== $form->get('destination')->getData()) {
            return $newTemplate;
        }

        $target = $form->get('targetTemplate')->getData();
        if ($target instanceof SurveyTemplate) {
            return $target;
        }

        $form->get('targetTemplate')->addError(new FormError($translator->trans('surveyImportDestinationMissingError')));

        return null;
    }

    /** Where an author coming back to the front door should be offered to pick up. */
    private function resumeRoute(Request $request): ?string
    {
        if (\is_array($request->getSession()->get(SurveyImportSession::PAYLOAD_KEY))) {
            return 'app_survey_import_preview';
        }

        return null !== $this->state($request)->path ? 'app_survey_import_prompt' : null;
    }

    /**
     * The folder the import was started from, or null - the root, or a folder that is not this
     * author's, which a hand-written `?folder=` is the only way to name.
     *
     * Read rather than trusted: the id was written into the session at a door that did not check it,
     * so ownership is verified here, once, where it is used.
     */
    private function rememberedFolder(Request $request, SurveyFolderRepository $folders): ?SurveyFolder
    {
        $folderId = $request->getSession()->get(SurveyImportSession::FOLDER_KEY);
        if (!\is_int($folderId)) {
            return null;
        }

        $folder = $folders->find($folderId);

        return $folder instanceof SurveyFolder && $folder->getOwner() === $this->currentUser() ? $folder : null;
    }

    /** @return list<string> */
    private function stepLabels(TranslatorInterface $translator): array
    {
        return array_map(static fn (string $key): string => $translator->trans($key), self::STEP_LABELS);
    }

    private function state(Request $request): SurveyAssistantState
    {
        $raw = $request->getSession()->get(SurveyImportSession::ASSISTANT_KEY);

        return SurveyAssistantState::fromArray(\is_array($raw) ? $raw : []);
    }

    private function save(Request $request, SurveyAssistantState $state): void
    {
        $request->getSession()->set(SurveyImportSession::ASSISTANT_KEY, $state->toArray());
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
