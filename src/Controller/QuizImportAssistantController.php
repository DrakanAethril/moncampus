<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PostValue;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizAssistantPath;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\InteractiveQuizImporterRegistry;
use App\Service\MixedJsonImporter;
use App\Service\QueryValue;
use App\Service\QuizAssistantRequest;
use App\Service\QuizAssistantState;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportImages;
use App\Service\QuizImportSession;
use App\Service\QuizPromptCatalog;
use App\Service\QuizSourceContext;
use App\Service\QuizSourceContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Des questions de quiz, d'où viennent-elles ? » - the assistant that walks a teacher from that
 * question to a bank of questions in their library.
 *
 * It replaces the one-page screen this controller's routes took over from
 * (`/library/quiz/import/interactive`, now a redirect). That page carried the mode choice, the
 * course block, the twelve type fragments, the image deposit, the prompt and the paste box at once,
 * in two columns - which read as two parallel things to do when they are four moments separated by
 * a round trip *outside* the application. Nothing said where to start, and nothing said you had to
 * leave. It is the same diagnosis, and the same remedy, as
 * App\Controller\SequenceImportController's step 2.
 *
 * ① Que voulez-vous faire ? · ② Le prompt · ③ Coller · ④ Vérifier.
 *
 * **Step 4 is not here.** It is App\Controller\QuizImportController::preview(), unchanged and shared
 * with the CSV/Kahoot route: the assistant's job ends when a document has been parsed into the
 * session, and the tunnel that turns a payload into a QuizTemplate already exists, already offers
 * « rattacher à la séance … », and already counts incomplete questions. Duplicating it to own all
 * four steps would have been the expensive way to change nothing.
 *
 * Nothing is written before that preview is confirmed.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class QuizImportAssistantController extends AbstractController
{
    /**
     * Translation keys - _wizard_steps.html.twig prints its labels as it gets them (it was written
     * for the UFA wizards, whose labels are built from a period's dates), so they are translated on
     * the way in rather than in the partial.
     *
     * @var list<string>
     */
    private const array STEP_LABELS = [
        'quizAssistantStep1Label', 'quizAssistantStep2Label', 'quizAssistantStep3Label', 'quizAssistantStep4Label',
    ];

    /**
     * Step 1 - « Que voulez-vous faire ? ». Four cards, one question.
     *
     * The CSV card leaves the assistant on the spot: a file is not a conversation, so it has no
     * prompt step and no paste step. It is a card rather than a tab because it answers this very
     * question - the pendant of « Je la saisis moi-même » on the séquence side.
     *
     * A link carrying a scope (the « Quiz » menus of a séquence or a séance, the arrival screen of
     * the séquence assistant) walks straight past this screen: the teacher has already answered by
     * clicking it, and asking again would be asking twice.
     */
    #[Route(path: '/library/quiz/import/assistant', name: 'app_library_quiz_assistant', methods: ['GET', 'POST'])]
    public function start(
        Request $request,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
        TranslatorInterface $translator,
    ): Response {
        if ($request->isMethod('POST')) {
            return $this->answerStepOne($request, $sequenceRepository, $seanceRepository);
        }

        // Deep link: ?sequence=…/?seance=… (+ optional mode/live) sets the state and skips ahead.
        $incoming = $this->fromQuery($request, $sequenceRepository, $seanceRepository);
        if (null !== $incoming) {
            $this->save($request, $incoming);

            return $this->redirectToRoute('app_library_quiz_assistant_prompt');
        }

        $state = $this->state($request);

        return $this->render('library/quiz_assistant_start.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 1,
            // Coming back to the front door does not throw away a conversion in progress - step 2
            // ends outside the application, sometimes for a while.
            'resumeRoute' => $this->resumeRoute($request, $state),
            'sequences' => $sequenceRepository->findForTeacher($this->currentUser()),
            'paths' => QuizAssistantPath::cases(),
        ]);
    }

    /**
     * Step 2 - « Voici comment demander vos questions ». Instructions, not a form: everything on it
     * feeds one block of text, and the only thing the teacher does is copy it.
     *
     * It carries what the one-page screen carried - the twelve type fragments and their « compatibles
     * concours live » filter, the image deposit and its short references, the course block with its
     * objectives/phases ticks and its character counter - plus what step 1 collected. The counter
     * still counts the text that is actually sent, because the four assembled variants are handed
     * over whole by App\Service\QuizSourceContext and the browser only picks one.
     */
    #[Route(path: '/library/quiz/import/assistant/prompt', name: 'app_library_quiz_assistant_prompt', methods: ['GET', 'POST'])]
    public function prompt(
        Request $request,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
        QuizSourceContextFactory $contextFactory,
        QuizImportImages $images,
        TranslatorInterface $translator,
    ): Response {
        $state = $this->state($request);
        if (null === $state->path || !$state->path->usesPrompt()) {
            return $this->redirectToRoute('app_library_quiz_assistant');
        }

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'quiz_assistant_prompt');
            $state = $state->withRequest(QuizAssistantRequest::fromArray(PostValue::all($request, 'demand')));
            $this->save($request, $state);

            return $this->redirectToRoute('app_library_quiz_assistant_paste');
        }

        $context = $this->contextOf($state, $contextFactory, $sequenceRepository, $seanceRepository);

        return $this->render('library/quiz_assistant_prompt.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 2,
            'path' => $state->path,
            'pathTranspose' => QuizAssistantPath::Transpose,
            'pathCourse' => QuizAssistantPath::Course,
            'pathSubject' => QuizAssistantPath::Subject,
            'demand' => $state->request->toArray(),
            'maxQuestionCount' => QuizAssistantRequest::MAX_QUESTION_COUNT,
            'depositedImages' => $images->batch()->all(),
            'promptEnvelope' => QuizPromptCatalog::envelope(),
            // The closing is decided here and not in the browser: the path is a step of its own now,
            // so two closings shipped to the page and switched by JS would be two texts to keep in
            // step for no gain at all.
            'promptClosing' => $state->path->generates()
                ? QuizPromptCatalog::typeChoice()
                : QuizPromptCatalog::transposeClosing(),
            // The request block travels as a shape plus its fallbacks, so the browser can refill it
            // on every keystroke without owning which lines exist. Empty on the transposition path:
            // it states no subject and no count - the teacher's own document holds all of it.
            'promptDemandTemplate' => $state->path->generates() ? QuizPromptCatalog::demandTemplate($state->isFromCourse()) : '',
            'promptDemandPlaceholders' => QuizPromptCatalog::demandPlaceholders(),
            'promptDemandExtraHeading' => QuizPromptCatalog::EXTRA_HEADING,
            'promptFragments' => QuizPromptCatalog::fragments(),
            'promptTypes' => array_map(static fn (QuestionType $case): array => [
                'value' => $case->value,
                'label' => $translator->trans($case->labelKey()),
                'live' => $case->isAvailableInLiveContest(),
            ], QuestionType::cases()),
            'liveOnly' => $state->liveOnly,
            'sourceContext' => null === $context ? null : [
                'title' => $context->title,
                'sentence' => $context->scopeSentence(),
                'hasObjectives' => $context->hasObjectives(),
                'hasPhases' => $context->hasPhases(),
                'isEmpty' => $context->isEmpty(),
                'max' => QuizPromptCatalog::MAX_CONTEXT_CHARACTERS,
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
     * Step 3 - « Collez ce que l'IA vous a rendu ». One box, full frame, and the ready-made examples.
     *
     * A document that will not parse is answered **422** and not 200: a POST rendered 200 is thrown
     * away by Turbo, and the teacher would watch their error message never appear. That one cost a
     * browser round trip on the séquence side; it is not paid twice.
     */
    #[Route(path: '/library/quiz/import/assistant/paste', name: 'app_library_quiz_assistant_paste', methods: ['GET', 'POST'])]
    public function paste(
        Request $request,
        InteractiveQuizImporterRegistry $registry,
        MixedJsonImporter $mixed,
        TranslatorInterface $translator,
    ): Response {
        $state = $this->state($request);
        if (null === $state->path || !$state->path->usesPrompt()) {
            return $this->redirectToRoute('app_library_quiz_assistant');
        }

        $json = '';
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'quiz_assistant_paste');
            $json = trim((string) $request->request->get('json'));

            try {
                $payload = $registry->forDocument($json, $mixed->family())
                    ->parse($json, $translator->trans('zoneImportPastedFileName'));
                $request->getSession()->set(QuizImportSession::PAYLOAD_KEY, $payload);
                // The course travels with the document rather than in the URL: a query string does
                // not survive the redirect the browser follows, and the preview needs it to offer
                // « rattacher à la séance … ».
                $request->getSession()->set(QuizImportSession::SOURCE_KEY, $state->scopeParams());

                return $this->redirectToRoute('app_library_quiz_import_preview');
            } catch (QuizCsvImportException $exception) {
                $error = $translator->trans($exception->getMessageKey(), $exception->getParameters());
            }
        }

        $example = (string) $request->query->get('example', '');

        return $this->render('library/quiz_assistant_paste.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 3,
            'json' => '' !== $json ? $json : $mixed->exampleJson($example),
            'exampleLabels' => $mixed->exampleLabels(),
            'error' => $error,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The old one-page screen's address, kept alive as a redirect rather than deleted.
     *
     * Deleting it would have broken every bookmark and every link written before today for the sake
     * of a URL nobody reads. It carries its scope and its live filter over, so an old link lands
     * exactly where its author meant it to.
     */
    #[Route(path: '/library/quiz/import/interactive', name: 'app_library_quiz_import_interactive', methods: ['GET'])]
    public function legacyInteractive(Request $request): Response
    {
        $params = [];
        foreach (['sequence', 'seance'] as $key) {
            if (QueryValue::int($request, $key) > 0) {
                $params[$key] = QueryValue::int($request, $key);
            }
        }
        if ($request->query->getBoolean('live')) {
            $params['live'] = 1;
        }
        if ('' !== (string) $request->query->get('mode', '')) {
            $params['mode'] = (string) $request->query->get('mode');
        }

        return $this->redirectToRoute('app_library_quiz_assistant', $params);
    }

    /**
     * Step 1's answer: which card, and for the course card, which séquence or séance.
     *
     * Access is checked on the séquence in both cases, séance included: a séance has no voter of its
     * own and its séquence is what owns the teacher's claim to it (App\Security\Voter\SequenceTemplateVoter).
     */
    private function answerStepOne(
        Request $request,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): Response {
        $this->assertCsrf($request, 'quiz_assistant_start');

        $path = QuizAssistantPath::tryFrom((string) $request->request->get('path'));
        if (null === $path) {
            return $this->redirectToRoute('app_library_quiz_assistant');
        }

        if (QuizAssistantPath::Csv === $path) {
            return $this->redirectToRoute('app_library_quiz_import');
        }

        $raw = ['path' => $path->value];
        if (QuizAssistantPath::Course === $path) {
            // One select, two kinds of answer: "sequence:7" or "seance:42". A pair of selects would
            // let a teacher pick a séance of another séquence and mean neither.
            $scope = (string) $request->request->get('scope');
            [$kind, $id] = array_pad(explode(':', $scope, 2), 2, '');
            $raw['seance' === $kind ? 'seanceId' : 'sequenceId'] = $id;
        }

        $state = QuizAssistantState::fromArray($raw);
        if (null === $state->path) {
            // The course card without a course - QuizAssistantState refuses it, and so does this.
            return $this->redirectToRoute('app_library_quiz_assistant');
        }

        $this->assertScopeIsReadable($state, $sequenceRepository, $seanceRepository);
        $this->save($request, $state);

        return $this->redirectToRoute('app_library_quiz_assistant_prompt');
    }

    /** A link that already answers step 1, or null when the query says nothing. */
    private function fromQuery(
        Request $request,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): ?QuizAssistantState {
        $seanceId = QueryValue::int($request, 'seance');
        $sequenceId = QueryValue::int($request, 'sequence');
        if ($seanceId <= 0 && $sequenceId <= 0) {
            return null;
        }

        // « transpose » from a course means "I have the questions, and they belong to that séance":
        // the scope is kept for the attachment, the prompt stays a transposition.
        $path = 'transpose' === $request->query->get('mode') ? QuizAssistantPath::Transpose : QuizAssistantPath::Course;

        $state = QuizAssistantState::fromArray([
            'path' => $path->value,
            'seanceId' => $seanceId,
            'sequenceId' => $sequenceId,
            'liveOnly' => $request->query->getBoolean('live'),
        ]);

        $this->assertScopeIsReadable($state, $sequenceRepository, $seanceRepository);

        return $state;
    }

    private function assertScopeIsReadable(
        QuizAssistantState $state,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): void {
        if (null !== $state->seanceId) {
            $seance = $seanceRepository->find($state->seanceId);
            if (!$seance instanceof SeanceTemplate || !$seance->getSequenceTemplate() instanceof SequenceTemplate) {
                throw $this->createNotFoundException();
            }
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $seance->getSequenceTemplate());

            return;
        }

        if (null !== $state->sequenceId) {
            $sequence = $sequenceRepository->find($state->sequenceId);
            if (!$sequence instanceof SequenceTemplate) {
                throw $this->createNotFoundException();
            }
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);
        }
    }

    private function contextOf(
        QuizAssistantState $state,
        QuizSourceContextFactory $factory,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): ?QuizSourceContext {
        if (null !== $state->seanceId) {
            $seance = $seanceRepository->find($state->seanceId) ?? throw $this->createNotFoundException();
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $seance->getSequenceTemplate() ?? throw $this->createNotFoundException());

            return $factory->forSeance($seance);
        }

        if (null !== $state->sequenceId) {
            $sequence = $sequenceRepository->find($state->sequenceId) ?? throw $this->createNotFoundException();
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);

            return $factory->forSequence($sequence);
        }

        return null;
    }

    /** Where a teacher coming back to the front door should be offered to pick up. */
    private function resumeRoute(Request $request, QuizAssistantState $state): ?string
    {
        if (\is_array($request->getSession()->get(QuizImportSession::PAYLOAD_KEY))) {
            return 'app_library_quiz_import_preview';
        }

        return null !== $state->path && $state->path->usesPrompt() ? 'app_library_quiz_assistant_prompt' : null;
    }

    /** @return list<string> */
    private function stepLabels(TranslatorInterface $translator): array
    {
        return array_map(static fn (string $key): string => $translator->trans($key), self::STEP_LABELS);
    }

    private function state(Request $request): QuizAssistantState
    {
        $raw = $request->getSession()->get(QuizImportSession::ASSISTANT_KEY);

        return QuizAssistantState::fromArray(\is_array($raw) ? $raw : []);
    }

    private function save(Request $request, QuizAssistantState $state): void
    {
        $request->getSession()->set(QuizImportSession::ASSISTANT_KEY, $state->toArray());
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
