<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\PostValue;
use App\Service\SequenceExampleCatalog;
use App\Service\SequenceImportException;
use App\Service\SequenceImportPouring;
use App\Service\SequenceImportWriter;
use App\Service\SequenceJsonImporter;
use App\Service\SequencePromptCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « J'ai déjà une séquence, je veux l'importer » - the assistant that converts a séquence a teacher
 * already owns into one the library can hold.
 *
 * It only ever **transposes**. Building a séquence from nothing with a language model is out of
 * scope and absent from the screen - not greyed out, not "bientôt": an intention an application
 * displays and does not keep costs more than it earns. Generation stays on the quiz side, where it
 * works.
 *
 * Four steps, one thing to do per screen, because the second one ends *outside* the application:
 * the teacher leaves with a prompt, converses with a model, and comes back with a document. An
 * earlier design put the prompt and the paste box side by side, which read as two parallel columns
 * when they are two moments separated by a round trip - nothing said where to start, and nothing
 * said you had to leave.
 *
 * ① Votre projet · ② Convertir · ③ Coller · ④ Vérifier, then an arrival screen.
 *
 * Nothing is written before step 4 is confirmed. The payload lives in the session, exactly like
 * App\Controller\QuizImportController's - a converted kit is ~30 KB of JSON, and step 2 can take a
 * quarter of an hour, so coming back must resume rather than restart.
 *
 * @phpstan-import-type SequenceImportPayload from SequenceJsonImporter
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SequenceImportController extends AbstractController
{
    private const string SESSION_KEY = 'sequence_import';

    private const string DESTINATION_NEW = 'new';

    private const string DESTINATION_SEQUENCE = 'sequence';

    private const string DESTINATION_SEANCE = 'seance';

    /**
     * Translation keys - _wizard_steps.html.twig prints its labels as they come, because the UFA
     * wizards it was written for pass strings built from a period's own dates. They are translated
     * on the way in (stepLabels()) rather than in the partial, so that contract stays one line.
     *
     * @var list<string>
     */
    private const array STEP_LABELS = [
        'sequenceImportStep1Label', 'sequenceImportStep2Label', 'sequenceImportStep3Label', 'sequenceImportStep4Label',
    ];

    /**
     * Step 1 - « Que voulez-vous faire ? ». Two cards and nothing else: import what I have, or fill
     * the ordinary form myself. The second is there at the same rank on purpose - without it the
     * assistant imposes a language model on a teacher who wants none, and with it the first card
     * becomes a choice. It is also the way out when a conversion goes badly.
     */
    #[Route(path: '/library/sequences/assistant', name: 'app_library_sequences_assistant', methods: ['GET'])]
    public function start(Request $request, SequenceTemplateRepository $repository, TranslatorInterface $translator): Response
    {
        $state = $this->state($request);

        return $this->render('library/sequence_import_start.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 1,
            // Coming back to the front door does not throw away a conversion in progress: step 2
            // ends outside the application, sometimes for a while.
            'resumeRoute' => $this->resumeRoute($state),
            'sequences' => $repository->findForTeacher($this->currentUser()),
        ]);
    }

    /**
     * Step 2 - « Voici comment demander la conversion ». A set of instructions, not a form: the only
     * thing the teacher does here is copy. The tag labels are asked for *before* the copy because
     * they go into the prompt - a model left to invent them writes "BTS SIO 2ème année" next to the
     * "BTS SIO 2" this teacher already uses, and the library grows a duplicate nothing will merge.
     */
    #[Route(path: '/library/sequences/assistant/convert', name: 'app_library_sequences_assistant_convert', methods: ['GET', 'POST'])]
    public function convert(
        Request $request,
        LibraryNiveauTagRepository $niveauTagRepository,
        LibraryOptionTagRepository $optionTagRepository,
        LibraryBlocTagRepository $blocTagRepository,
        TranslatorInterface $translator,
    ): Response {
        $state = $this->state($request);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'sequence_import_convert');
            $state['labels'] = [
                'niveau' => trim((string) $request->request->get('niveau')),
                'option' => trim((string) $request->request->get('option')),
                'blocs' => $this->submittedLabels($request, 'blocs'),
            ];
            $state['skippedConvert'] = false;
            $this->save($request, $state);

            return $this->redirectToRoute('app_library_sequences_assistant_paste');
        }

        $labels = $this->labels($state);

        return $this->render('library/sequence_import_convert.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 2,
            'promptBody' => SequencePromptCatalog::body(),
            // The labels line is built twice - here for the first render, and in the browser as the
            // teacher ticks - so both builders take the same pieces rather than each holding a copy.
            'promptPieces' => [
                'placeholder' => SequencePromptCatalog::LABELS_PLACEHOLDER,
                'intro' => SequencePromptCatalog::LABELS_INTRO,
                'niveau' => SequencePromptCatalog::NIVEAU_TEMPLATE,
                'option' => SequencePromptCatalog::OPTION_TEMPLATE,
                'blocs' => SequencePromptCatalog::BLOCS_TEMPLATE,
                'none' => SequencePromptCatalog::NO_LABELS,
            ],
            'prompt' => SequencePromptCatalog::prompt($labels['niveau'], $labels['option'], $labels['blocs']),
            'currentNiveauLabel' => $labels['niveau'],
            'currentOptionLabel' => $labels['option'],
            'currentBlocLabels' => $labels['blocs'],
            'tagOptions' => [
                'niveau' => $niveauTagRepository->findAllForTeacher($this->currentUser()),
                'option' => $optionTagRepository->findAllForTeacher($this->currentUser()),
                'blocs' => $blocTagRepository->findAllForTeacher($this->currentUser()),
            ],
        ]);
    }

    /**
     * Step 3 - « Collez ce que l'IA vous a rendu ». One box, full frame.
     *
     * Reached directly by a teacher who already holds a document at this format, in which case step
     * 2 is marked as walked past rather than removed - a step that vanishes renumbers the ones after
     * it, and the parcours stops being readable halfway through.
     */
    #[Route(path: '/library/sequences/assistant/paste', name: 'app_library_sequences_assistant_paste', methods: ['GET', 'POST'])]
    public function paste(Request $request, SequenceJsonImporter $importer, TranslatorInterface $translator): Response
    {
        $state = $this->state($request);

        if ($request->query->getBoolean('skip')) {
            $state['skippedConvert'] = true;
            $this->save($request, $state);

            return $this->redirectToRoute('app_library_sequences_assistant_paste');
        }

        // The worked example, loaded into the box on request. A teacher who has never seen the format
        // has one question here - "what is it supposed to look like?" - and a real document answers it
        // where a description does not (App\Service\SequenceExampleCatalog).
        $json = $request->query->getBoolean('example') ? SequenceExampleCatalog::ansibleKit() : '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'sequence_import_paste');
            $json = trim((string) $request->request->get('json'));

            try {
                $state['payload'] = $importer->parse($json, $translator->trans('sequenceImportPastedFileName'));
                $this->save($request, $state);

                return $this->redirectToRoute('app_library_sequences_assistant_review');
            } catch (SequenceImportException $exception) {
                $error = $translator->trans($exception->getMessageKey(), $exception->getParameters());
            }
        }

        // 422 rather than 200 when the document was refused: a Turbo-driven form submission that
        // neither redirects nor reports an error status is discarded, and the message explaining
        // what is wrong with the pasted block would never reach the screen.
        return $this->render('library/sequence_import_paste.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 3,
            'skippedSteps' => true === ($state['skippedConvert'] ?? false) ? [2] : [],
            'json' => $json,
            'error' => $error,
            'format' => SequenceJsonImporter::FORMAT,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Step 4 - « Voici ce qui va être créé », and the only screen that writes.
     *
     * Two things share it. The preview, which is what a confirmation screen owes the teacher, and
     * the transposition report - what the conversion deduced, what it could not place, what it left
     * empty. The second is the price of transposing: MonCampus is poorer than a real séquence sheet,
     * and without the panel the gap between the document and the imported fiche is visible nowhere.
     * An import that loses part of a document in silence is worse than one that fails.
     */
    #[Route(path: '/library/sequences/assistant/review', name: 'app_library_sequences_assistant_review', methods: ['GET', 'POST'])]
    public function review(
        Request $request,
        EntityManagerInterface $entityManager,
        SequenceImportWriter $writer,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
        TranslatorInterface $translator,
    ): Response {
        $state = $this->state($request);
        // The session holds what SequenceJsonImporter::parse() put there one request earlier, and
        // nothing else writes this key - so the shape is a promise this controller keeps itself.
        /** @var SequenceImportPayload|null $payload */
        $payload = \is_array($state['payload'] ?? null) ? $state['payload'] : null;
        if (null === $payload) {
            $this->addFlash('warning', 'sequenceImportExpiredFlashMessage');

            return $this->redirectToRoute('app_library_sequences_assistant_paste');
        }

        $sequences = $sequenceRepository->findForTeacher($this->currentUser());

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'sequence_import_review');
            $payload = SequenceImportPouring::withIdentity(
                $payload,
                trim((string) $request->request->get('titre')),
                trim((string) $request->request->get('niveau')),
                trim((string) $request->request->get('option')),
                $this->submittedLabels($request, 'blocs'),
            );
            $payload = SequenceImportPouring::apply($payload, $this->decisions($request));

            $state['payload'] = $payload;
            $this->save($request, $state);

            // Creating with blocks still undecided would drop them at the moment the séquence is
            // written, and the payload is gone straight after - which is the silent loss this whole
            // panel exists to prevent, arriving through the panel itself. "Écarter" is one click
            // away, so the friction is a click and what it buys is that nothing leaves without an
            // answer.
            $pending = SequenceImportPouring::pendingCount($payload);
            if ('' === (string) $request->request->get('confirm') || $pending > 0) {
                if ($pending > 0 && '' !== (string) $request->request->get('confirm')) {
                    $this->addFlash('warning', 'sequenceImportDecisionsPendingFlashMessage');
                }

                return $this->redirectToRoute('app_library_sequences_assistant_review');
            }

            $sequence = $this->write($payload, $request, $writer, $sequenceRepository, $seanceRepository);
            $entityManager->persist($sequence);
            $entityManager->flush();

            $request->getSession()->remove(self::SESSION_KEY);

            return $this->redirectToRoute('app_library_sequences_assistant_done', ['id' => $sequence->getId()]);
        }

        return $this->render('library/sequence_import_review.html.twig', [
            'stepLabels' => $this->stepLabels($translator),
            'currentStepIndex' => 4,
            'skippedSteps' => true === ($state['skippedConvert'] ?? false) ? [2] : [],
            'payload' => $payload,
            'targets' => SequenceImportPouring::targets($payload),
            'discardValue' => SequenceImportPouring::DISCARD,
            'sequences' => $sequences,
            'seances' => $seanceRepository->findBy(['sequenceTemplate' => $sequences], ['ordre' => 'ASC']),
        ]);
    }

    /**
     * The arrival - « C'est créé. Et maintenant ? ». Outside the step numbering, and it names what
     * comes next instead of dropping the teacher back onto a list: the imported séquence is the
     * starting point of the quizzes, and the assistant is the one place that can say so.
     */
    #[Route(path: '/library/sequences/assistant/done/{id}', name: 'app_library_sequences_assistant_done', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function done(int $id, SequenceTemplateRepository $repository): Response
    {
        $sequence = $repository->find($id);
        if (!$sequence instanceof SequenceTemplate) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);

        return $this->render('library/sequence_import_done.html.twig', [
            'sequenceTemplate' => $sequence,
            'phaseCount' => array_sum(array_map(
                static fn (SeanceTemplate $seance): int => $seance->getSeancePhaseTemplates()->count(),
                $sequence->getSeanceTemplates()->toArray(),
            )),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(
        array $payload,
        Request $request,
        SequenceImportWriter $writer,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): SequenceTemplate {
        $destination = (string) $request->request->get('destination', self::DESTINATION_NEW);

        if (self::DESTINATION_SEQUENCE === $destination) {
            $target = $sequenceRepository->find((int) $request->request->get('targetSequence'));
            if ($target instanceof SequenceTemplate) {
                $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $target);
                $writer->appendSeances($target, $payload);
                $this->addFlash('success', 'sequenceImportSeancesAppendedFlashMessage');

                return $target;
            }
        }

        if (self::DESTINATION_SEANCE === $destination) {
            $seance = $seanceRepository->find((int) $request->request->get('targetSeance'));
            $sequence = $seance?->getSequenceTemplate();
            if ($seance instanceof SeanceTemplate && $sequence instanceof SequenceTemplate) {
                $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);
                $writer->completeSeance($seance, $payload);
                $this->addFlash('success', 'sequenceImportSeanceCompletedFlashMessage');

                return $sequence;
            }
        }

        $this->addFlash('success', 'sequenceImportCreatedFlashMessage');

        return $writer->createSequence(
            $this->currentUser(),
            $payload,
            \count($sequenceRepository->findForTeacher($this->currentUser())) + 1,
        );
    }

    /**
     * The step names, translated. The shared partial prints what it is given.
     *
     * @return list<string>
     */
    private function stepLabels(TranslatorInterface $translator): array
    {
        return array_map(static fn (string $key): string => $translator->trans($key), self::STEP_LABELS);
    }

    /**
     * A repeated text field, read as the list of labels it is - a checkbox group posts an array of
     * whatever the browser sent, which is `mixed` until somebody says otherwise.
     *
     * @return list<string>
     */
    private function submittedLabels(Request $request, string $field): array
    {
        $labels = [];
        foreach (PostValue::all($request, $field) as $label) {
            if (\is_scalar($label) && '' !== trim((string) $label)) {
                $labels[] = trim((string) $label);
            }
        }

        return $labels;
    }

    /** @return array<int, string> unplaced block index => target path or DISCARD */
    private function decisions(Request $request): array
    {
        $decisions = [];
        foreach (PostValue::all($request, 'unplaced') as $index => $target) {
            if (\is_scalar($target) && '' !== (string) $target) {
                $decisions[(int) $index] = (string) $target;
            }
        }

        return $decisions;
    }

    /**
     * Where "reprendre" leads: the furthest step this session has actually reached. A teacher who
     * left after copying the prompt comes back to the paste box, not to the first question.
     *
     * @param array<string, mixed> $state
     */
    private function resumeRoute(array $state): ?string
    {
        if (\is_array($state['payload'] ?? null)) {
            return 'app_library_sequences_assistant_review';
        }

        if (\is_array($state['labels'] ?? null)) {
            return 'app_library_sequences_assistant_paste';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{niveau: ?string, option: ?string, blocs: list<string>}
     */
    private function labels(array $state): array
    {
        $labels = \is_array($state['labels'] ?? null) ? $state['labels'] : [];

        $blocs = [];
        foreach (\is_array($labels['blocs'] ?? null) ? $labels['blocs'] : [] as $label) {
            if (\is_scalar($label) && '' !== trim((string) $label)) {
                $blocs[] = trim((string) $label);
            }
        }

        return [
            'niveau' => \is_scalar($labels['niveau'] ?? null) ? (string) $labels['niveau'] : null,
            'option' => \is_scalar($labels['option'] ?? null) ? (string) $labels['option'] : null,
            'blocs' => $blocs,
        ];
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $state = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function save(Request $request, array $state): void
    {
        $request->getSession()->set(self::SESSION_KEY, $state);
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
