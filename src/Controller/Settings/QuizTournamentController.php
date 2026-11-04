<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\QuizTournamentType;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\QuizTournamentRepository;
use App\Security\Voter\QuizTournamentVoter;
use App\Service\LibraryPickerTree;
use App\Service\QuizTournament\QuizTournamentEditor;
use App\Service\QuizTournament\QuizTournamentReserve;
use App\Service\StagedUpload;
use App\Service\StructureTargeting\StructureTargeting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paramètres › Pédagogique, onglet « Tournois » (design/validated/quiz-tournament.md §3 and §15): the
 * administrators create, edit and open the quiz tournaments.
 *
 * Every tournament is listed, opened or not. A tournament is born closed and « Ouvrir » is its own
 * gesture; after its first duel only the name, the end and the targeting may move, and it can no
 * longer be deleted. QuizTournamentVoter (CREATE, MANAGE) is the only arbiter - a refusal is a 404,
 * like the feature switched off.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class QuizTournamentController extends AbstractController
{
    private const string OPEN_TOKEN = 'quiz_tournament_open';
    private const string DELETE_TOKEN = 'quiz_tournament_delete';

    public function __construct(
        private readonly QuizTournamentRepository $tournaments,
        private readonly QuizTournamentEditor $editor,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/settings/tournaments', name: 'app_settings_quiz_tournaments', methods: ['GET'])]
    public function index(StructureTargeting $targeting): Response
    {
        $this->denyUnless(QuizTournamentVoter::CREATE);

        $rows = [];
        foreach ($this->tournaments->findAllOrdered() as $tournament) {
            $rows[] = [
                'tournament' => $tournament,
                'status' => $tournament->statusAt($this->clock->now()),
                'reached' => \count(array_filter(
                    $targeting->reachedStudents($tournament),
                    static fn ($student): bool => \in_array('ROLE_STUDENT', $student->user->getRoles(), true),
                )),
                'locked' => $this->editor->isLocked($tournament),
            ];
        }

        return $this->render('settings/pedagogique.html.twig', [
            'activeTab' => 'quiz_tournaments',
            'tournamentRows' => $rows,
        ]);
    }

    #[Route(path: '/settings/tournaments/new', name: 'app_settings_quiz_tournaments_new', methods: ['GET', 'POST'])]
    public function new(Request $request, QuizTemplateRepository $quizzes, QuizFolderRepository $folders, LibraryPickerTree $pickerTree): Response
    {
        $this->denyUnless(QuizTournamentVoter::CREATE);
        $admin = $this->currentUser();
        $library = $quizzes->findPickable($admin);

        $form = $this->createForm(QuizTournamentType::class, $this->editor->formData(null), [
            'sourceChoices' => $library,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->holds($form, null, false)) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $tournament = $this->editor->create($this->typed($data), $admin);
            $this->entityManager->flush();

            $logo = $form->get('logo')->getData();
            if ($logo instanceof StagedUpload) {
                $this->editor->storeLogo($tournament, $logo);
                $this->entityManager->flush();
            }

            $this->addFlash('success', 'quizTournamentCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_quiz_tournaments');
        }

        return $this->renderForm($form, null, $library, $folders, $pickerTree, $admin);
    }

    #[Route(path: '/settings/tournaments/{id}/edit', name: 'app_settings_quiz_tournaments_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, QuizTemplateRepository $quizzes, QuizFolderRepository $folders, LibraryPickerTree $pickerTree): Response
    {
        $tournament = $this->find($id);
        $this->denyUnless(QuizTournamentVoter::MANAGE, $tournament);
        $locked = $this->editor->isLocked($tournament);
        // The sources come from the library of the administrator who created the tournament (§3).
        $library = $quizzes->findPickable($tournament->getCreatedBy());

        $form = $this->createForm(QuizTournamentType::class, $this->editor->formData($tournament), [
            'locked' => $locked,
            'hasLogo' => null !== $tournament->getLogoKey(),
            'tournament' => $tournament,
            'sourceChoices' => $this->withCurrentSources($library, $tournament),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->holds($form, $tournament, $locked)) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $this->editor->apply($tournament, $this->typed($data), $this->currentUser(), $this->clock->now());

            if (!$locked) {
                $logo = $form->get('logo')->getData();
                if ($logo instanceof StagedUpload) {
                    $this->editor->storeLogo($tournament, $logo);
                } elseif ($form->has('removeLogo') && true === $form->get('removeLogo')->getData()) {
                    $this->editor->removeLogo($tournament);
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'quizTournamentSavedFlashMessage');

            return $this->redirectToRoute('app_settings_quiz_tournaments');
        }

        return $this->renderForm($form, $tournament, $library, $folders, $pickerTree, $tournament->getCreatedBy());
    }

    #[Route(path: '/settings/tournaments/{id}/open', name: 'app_settings_quiz_tournaments_open', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function open(int $id, Request $request): Response
    {
        $tournament = $this->find($id);
        $this->denyUnless(QuizTournamentVoter::MANAGE, $tournament);
        if (!$this->isCsrfTokenValid(self::OPEN_TOKEN.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$tournament->isOpened()) {
            $this->editor->open($tournament, $this->currentUser(), $this->clock->now());
            $this->entityManager->flush();
            $this->addFlash('success', 'quizTournamentOpenedFlashMessage');
        }

        return $this->redirectToRoute('app_settings_quiz_tournaments');
    }

    #[Route(path: '/settings/tournaments/{id}/delete', name: 'app_settings_quiz_tournaments_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $tournament = $this->find($id);
        $this->denyUnless(QuizTournamentVoter::MANAGE, $tournament);
        if (!$this->isCsrfTokenValid(self::DELETE_TOKEN.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->editor->canDelete($tournament)) {
            $this->editor->delete($tournament);
            $this->addFlash('success', 'quizTournamentDeletedFlashMessage');
        } else {
            $this->addFlash('error', 'quizTournamentNotDeletableFlashMessage');
        }

        return $this->redirectToRoute('app_settings_quiz_tournaments');
    }

    /**
     * The refusals of QuizTournamentEditor, each on its field. False when one was found.
     *
     * @param FormInterface<mixed> $form
     */
    private function holds(FormInterface $form, ?QuizTournament $tournament, bool $locked): bool
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        $refusals = $this->editor->refusals($this->typed($data), $tournament, $locked);
        foreach ($refusals as $refusal) {
            $field = $form->has($refusal['field']) ? $form->get($refusal['field']) : $form;
            $field->addError(new FormError($this->translator->trans($refusal['key'], $refusal['params'])));
        }

        return [] === $refusals;
    }

    /**
     * @param FormInterface<mixed> $form
     * @param list<QuizTemplate>   $library
     */
    private function renderForm(FormInterface $form, ?QuizTournament $tournament, array $library, QuizFolderRepository $folders, LibraryPickerTree $pickerTree, User $owner): Response
    {
        $locked = null !== $tournament && $this->editor->isLocked($tournament);

        // What the sources give a duel, on the rows as submitted (or as stored on a first display).
        $reserve = null;
        if (!$locked && $form->has('sources')) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $typed = $this->typed($data);
            $sources = $typed['sources'] ?? [];
            $reserve = $this->editor->reserveOf($sources, $typed['questionsPerDuel'] ?? QuizTournament::DEFAULT_QUESTIONS_PER_DUEL, $typed['maxTrueFalsePerDuel'] ?? QuizTournament::DEFAULT_MAX_TRUE_FALSE);
        } elseif (null !== $tournament) {
            $reserve = $this->editor->reserveOf($tournament->getSources(), $tournament->getQuestionsPerDuel(), $tournament->getMaxTrueFalsePerDuel());
        }

        $admitted = [];
        foreach ($library as $quiz) {
            $count = 0;
            foreach ($quiz->getQuestions() as $question) {
                if ($question->getType()->isPlayableInTournament()) {
                    ++$count;
                }
            }
            $admitted[(int) $quiz->getId()] = ['questions' => $count, 'defaultCount' => 0];
        }

        return $this->render('settings/quiz_tournament/form.html.twig', [
            'form' => $form,
            'tournament' => $tournament,
            'locked' => $locked,
            'reserve' => $reserve instanceof QuizTournamentReserve && ($reserve->admitted > 0 || $reserve->ignoredCount() > 0) ? $reserve : null,
            'admittedByQuiz' => $admitted,
            'pickerTree' => $pickerTree->build(
                array_map(static fn ($folder): array => [
                    'id' => (int) $folder->getId(),
                    'parentId' => $folder->getParent()?->getId(),
                    'name' => $folder->getName(),
                ], $folders->findAllFor($owner)),
                array_map(static fn (QuizTemplate $quiz): array => [
                    'id' => (int) $quiz->getId(),
                    'folderId' => $quiz->getFolder()?->getId(),
                    'label' => $quiz->getName() ?? '',
                    'count' => $admitted[(int) $quiz->getId()]['questions'],
                ], $library),
            ),
            'openToken' => null !== $tournament ? self::OPEN_TOKEN.$tournament->getId() : null,
            'deleteToken' => null !== $tournament ? self::DELETE_TOKEN.$tournament->getId() : null,
            'deletable' => null !== $tournament && !$locked,
        ]);
    }

    /**
     * The form's array as QuizTournamentEditor reads it - the keys of a locked form are simply
     * absent.
     *
     * @param array<string, mixed> $data
     *
     * @return array{
     *     name: string|null,
     *     startsAt?: \DateTimeImmutable|null,
     *     endsAt: \DateTimeImmutable|null,
     *     targetSections: iterable<\App\Entity\Section>,
     *     targetTracks: iterable<\App\Entity\Track>,
     *     targetPrograms: iterable<\App\Entity\Program>,
     *     sources?: iterable<QuizTemplate|null>,
     *     questionsPerDuel?: int|null,
     *     maxTrueFalsePerDuel?: int|null,
     *     secondsPerQuestion?: int|null,
     *     secondsPerMultiQuestion?: int|null,
     *     palette?: \App\Enum\QuizTournamentPalette|null,
     * }
     */
    private function typed(array $data): array
    {
        /** @var iterable<\App\Entity\Section> $sections */
        $sections = is_iterable($data['targetSections'] ?? null) ? $data['targetSections'] : [];
        /** @var iterable<\App\Entity\Track> $tracks */
        $tracks = is_iterable($data['targetTracks'] ?? null) ? $data['targetTracks'] : [];
        /** @var iterable<\App\Entity\Program> $programs */
        $programs = is_iterable($data['targetPrograms'] ?? null) ? $data['targetPrograms'] : [];

        $typed = [
            'name' => \is_string($data['name'] ?? null) ? trim($data['name']) : null,
            'endsAt' => ($data['endsAt'] ?? null) instanceof \DateTimeImmutable ? $data['endsAt'] : null,
            'targetSections' => $sections,
            'targetTracks' => $tracks,
            'targetPrograms' => $programs,
        ];
        if (\array_key_exists('startsAt', $data)) {
            $typed['startsAt'] = $data['startsAt'] instanceof \DateTimeImmutable ? $data['startsAt'] : null;
        }
        if (\array_key_exists('sources', $data)) {
            /** @var iterable<QuizTemplate|null> $sources */
            $sources = is_iterable($data['sources']) ? $data['sources'] : [];
            $typed['sources'] = $sources;
        }
        foreach (['questionsPerDuel', 'maxTrueFalsePerDuel', 'secondsPerQuestion', 'secondsPerMultiQuestion'] as $key) {
            if (\array_key_exists($key, $data)) {
                $typed[$key] = \is_int($data[$key]) ? $data[$key] : null;
            }
        }
        if (\array_key_exists('palette', $data)) {
            $typed['palette'] = $data['palette'] instanceof \App\Enum\QuizTournamentPalette ? $data['palette'] : null;
        }

        return $typed;
    }

    /**
     * The creator's library plus the sources already named - a source the creator has since moved
     * out of their library must not vanish from the form.
     *
     * @param list<QuizTemplate> $library
     *
     * @return list<QuizTemplate>
     */
    private function withCurrentSources(array $library, QuizTournament $tournament): array
    {
        foreach ($tournament->getSources() as $source) {
            if (!\in_array($source, $library, true)) {
                $library[] = $source;
            }
        }

        return $library;
    }

    private function find(int $id): QuizTournament
    {
        return $this->tournaments->find($id) ?? throw $this->createNotFoundException();
    }

    private function denyUnless(string $attribute, ?QuizTournament $tournament = null): void
    {
        if (!$this->isGranted($attribute, $tournament)) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
