<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\BlankMode;
use App\Enum\MatchingSideKind;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Enum\ZoneSupportKind;
use App\Form\QuizLaunchType;
use App\Form\QuizQuestionType;
use App\Form\QuizTemplateSettingsType;
use App\Repository\ProgramRepository;
use App\Repository\QuizQuestionRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\FileUploadService;
use App\Service\FormValue;
use App\Service\MatchingImageStore;
use App\Service\MatchingJsonImporter;
use App\Service\QuizAnswerChecker;
use App\Service\QuizInstantiationService;
use App\Service\ZoneJsonImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// A teacher's personal quiz library - see design/design_campus_manager/README.md's "Générateur de
// quiz" section and reference/Générateur de quiz.dc.html (screens 1a/1b/1n/1c). Deliberately not
// Program-scoped, exactly like SequenceLibraryController: QuizTemplate/QuizQuestion/QuizAnswer are
// owned by a teacher, browsable only by that teacher (or staff, via QuizTemplateVoter::EDIT).
// launch() bridges into the Program-scoped side (App\Controller\ProgramQuizController), same
// reasoning as SequenceLibraryController::instantiate().
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class QuizLibraryController extends AbstractController
{
    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

    // Rows per page in screen 1b's question bank. Matches the "Mes quiz" table's own page length
    // (quiz_list.html.twig) rather than the mockup's five rows, which are just what fits the
    // fixed-height card the design was drawn in.
    private const int QUESTIONS_PER_PAGE = 10;

    #[Route(path: '/library/quiz', name: 'app_library_quiz')]
    public function list(): Response
    {
        return $this->render('library/quiz_list.html.twig');
    }

    #[Route(path: '/library/quiz/data', name: 'app_library_quiz_data')]
    public function data(Request $request, QuizTemplateRepository $repository, TranslatorInterface $translator): JsonResponse
    {
        $templates = $repository->findForTeacher($this->currentUser());
        $total = \count($templates);

        return $this->json([
            'draw' => $request->query->getInt('draw', 1),
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => array_map(fn (QuizTemplate $template): array => $this->rowForTemplate($template, $translator), $templates),
        ]);
    }

    // "+ Nouveau quiz" (1a) opens the Paramètres tab (1n) on a transient, not-yet-persisted
    // QuizTemplate - nothing is written to the database until the teacher actually submits that
    // form, at which point this same action persists it and drops the teacher into the question
    // editor (1b), exactly like the previous immediate-create behaviour.
    #[Route(path: '/library/quiz/new', name: 'app_library_quiz_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $template = new QuizTemplate($this->currentUser());
        $template->setName($translator->trans('quizTemplateDefaultNewName'));
        $template->setCreatedBy($this->currentUser());

        $form = $this->createForm(QuizTemplateSettingsType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $template->setLastUpdatedBy($this->currentUser());
            $template->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->persist($template);
            $entityManager->flush();

            $this->addFlash('success', 'quizTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
        }

        return $this->render('library/quiz_settings.html.twig', [
            'quizTemplate' => $template,
            'form' => $form,
            'isNew' => true,
        ]);
    }

    #[Route(path: '/library/quiz/{id}/duplicate', name: 'app_library_quiz_duplicate', methods: ['POST'])]
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore, TranslatorInterface $translator): JsonResponse
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        if (!$this->isCsrfTokenValid('library_quiz_duplicate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $copy = new QuizTemplate($template->getTeacher());
        $copy->setName($translator->trans('quizTemplateDuplicateNameTemplate', ['%name%' => $template->getName()]));
        $copy->setSubject($template->getSubject());
        $copy->setDescription($template->getDescription());
        $copy->setDefaultQuestionCount($template->getDefaultQuestionCount());
        $copy->setDefaultSecondsPerQuestion($template->getDefaultSecondsPerQuestion());
        $copy->setDefaultSameQuestionsForAll($template->isDefaultSameQuestionsForAll());
        $copy->setDefaultQuestionOrderPerStudent($template->isDefaultQuestionOrderPerStudent());
        $copy->setDefaultAnswerOrderPerStudent($template->isDefaultAnswerOrderPerStudent());
        $copy->setCreatedBy($this->currentUser());

        foreach ($template->getQuestions() as $question) {
            $questionCopy = new QuizQuestion($copy);
            $questionCopy->setType($question->getType());
            $questionCopy->setDifficulty($question->getDifficulty());
            $questionCopy->setLabel($question->getLabel());
            $questionCopy->setOrderIndex($question->getOrderIndex());
            $questionCopy->setBlanksConfig($question->getBlanksConfig());
            $questionCopy->setZoneConfig($question->getZoneConfig());
            $questionCopy->setMatchingConfig($matchingImageStore->copyImages($question->getMatchingConfig()));
            $questionCopy->setPoints($question->getPoints());
            $questionCopy->setExplanation($question->getExplanation());

            if (null !== $question->getImageStorageKey()) {
                $newKey = self::IMAGE_UPLOAD_PREFIX.bin2hex(random_bytes(16)).'.'.pathinfo($question->getImageStorageKey(), \PATHINFO_EXTENSION);
                $fileUploadService->copy($question->getImageStorageKey(), $newKey);
                $questionCopy->setImageStorageKey($newKey);
            }

            foreach ($question->getAnswers() as $answer) {
                $answerCopy = new QuizAnswer($questionCopy);
                $answerCopy->setLabel($answer->getLabel());
                $answerCopy->setIsCorrect($answer->isCorrect());
                $answerCopy->setOrderIndex($answer->getOrderIndex());
                $questionCopy->addAnswer($answerCopy);
            }

            $copy->addQuestion($questionCopy);
        }

        $entityManager->persist($copy);
        $entityManager->flush();

        return $this->json(['redirectUrl' => $this->generateUrl('app_library_quiz_questions', ['id' => $copy->getId()])]);
    }

    #[Route(path: '/library/quiz/{id}/remove', name: 'app_library_quiz_remove', methods: ['POST'])]
    public function remove(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore): JsonResponse
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        if (!$this->isCsrfTokenValid('library_quiz_remove', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        foreach ($template->getQuestions() as $question) {
            if (null !== $question->getImageStorageKey()) {
                $fileUploadService->delete($question->getImageStorageKey());
            }
            // Same pair as questionRemove(): an apparier question's images hang off its config
            // rather than off a column, so they need their own walk or the bucket keeps them.
            $matchingImageStore->deleteImages($question);
        }

        $entityManager->remove($template);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/library/quiz/{id}/settings', name: 'app_library_quiz_settings')]
    public function settings(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $canEdit = $this->isGranted(QuizTemplateVoter::EDIT, $template);
        if (!$canEdit) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(QuizTemplateSettingsType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $template->setLastUpdatedBy($this->currentUser());
            $template->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'quizTemplateUpdatedFlashMessage');

            return $this->redirectToRoute('app_library_quiz_settings', ['id' => $template->getId()]);
        }

        return $this->render('library/quiz_settings.html.twig', [
            'quizTemplate' => $template,
            'form' => $form,
            'isNew' => false,
        ]);
    }

    // "Tester" tab - the teacher's own dry-run of the template, direct on QuizQuestion/QuizAnswer:
    // no QuizInstance/QuizAttempt is ever created and nothing is persisted, unlike launch() below
    // which creates real, student-facing data against a Program. Grading logic intentionally
    // duplicates App\Service\QuizAttemptGrader's match arms rather than generalizing that service
    // for two unrelated entity hierarchies (QuizQuestion/QuizAnswer here vs
    // QuizInstanceQuestion/QuizInstanceAnswer there).
    #[Route(path: '/library/quiz/{id}/test', name: 'app_library_quiz_test')]
    public function test(int $id, Request $request, QuizTemplateRepository $repository, QuizAnswerChecker $answerChecker): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        $questions = $template->getQuestions()->toArray();
        // GET, not POST: a Turbo Drive form submission must get back a redirect or a
        // turbo-stream response, never a plain 200 render (see memory: "Turbo form-redirect
        // gotcha") - this is a "show a result" form with no mutation, exactly the documented
        // case for staying GET.
        $submitted = $request->query->getBoolean('submitted');
        $results = [];
        $correctCount = null;

        // QuizQuestion::$answers is always fetched orderIndex ASC (see that entity's mapping), which
        // for an "ordre" question IS the correct sequence - shown as-is that would make the "Tester"
        // form trivial. Shuffle a display-only copy per question, same purpose (not the same
        // mechanism) as QuizDrawService::orderAnswers() for real students.
        $ordreAnswerOrder = [];
        // Same reasoning one level down for a texte à trous in banque mode: the bank is built
        // answers-first, in text order, so unshuffled it would spell out the solution.
        $wordBanks = [];
        // And once more for a légende's labels, which come out zone-first in definition order.
        $zoneChoiceSets = [];
        $matchingChoiceSets = [];
        $matchingPairSets = [];
        foreach ($questions as $question) {
            if (QuestionType::Ordre === $question->getType()) {
                $shuffled = $question->getAnswers()->toArray();
                shuffle($shuffled);
                $ordreAnswerOrder[$question->getId()] = $shuffled;
            }
            if (QuestionType::TexteATrous === $question->getType()) {
                $bank = $question->getWordBank();
                shuffle($bank);
                $wordBanks[$question->getId()] = $bank;
            }
            if (QuestionType::Legende === $question->getType()) {
                $choices = $question->getLegendeChoices();
                shuffle($choices);
                $zoneChoiceSets[$question->getId()] = $choices;
            }
            // And once more for an apparier's two columns, whose definition order puts each answer
            // directly opposite its own clue.
            if (QuestionType::Apparier === $question->getType()) {
                $choices = $question->getMatchingChoices();
                shuffle($choices);
                $matchingChoiceSets[$question->getId()] = $choices;
                $pairs = $question->getMatchingPairs();
                shuffle($pairs);
                $matchingPairSets[$question->getId()] = $pairs;
            }
        }

        if ($submitted) {
            $submittedAnswers = $request->query->all('answers');
            $submittedBlanks = $request->query->all('blanks');
            $submittedZones = $request->query->all('zones');
            $submittedPlacements = $request->query->all('placements');
            $submittedPairs = $request->query->all('pairs');
            $correctCount = 0;

            foreach ($questions as $question) {
                $selectedIds = array_map(intval(...), $submittedAnswers[$question->getId()] ?? []);
                $blankResponses = array_map(strval(...), $submittedBlanks[$question->getId()] ?? []);
                $zoneResponses = $this->testZoneResponses($question, $submittedZones, $submittedPlacements);
                $matchingResponses = $this->testMatchingResponses($question, $submittedPairs);
                // A texte à trous is graded here the same all-or-nothing way as every other type:
                // this tab answers "does my question work?", not "what would a student score?".
                $answers = $question->getType()->usesAnswerRows() ? $this->answerRows($question) : [];
                $isCorrect = $answerChecker->isCorrect($question, $answers, $selectedIds, $blankResponses, $zoneResponses, $matchingResponses);
                $results[$question->getId()] = [
                    'isCorrect' => $isCorrect,
                    'blankResponses' => $blankResponses,
                    'zoneResponses' => $zoneResponses,
                    'matchingResponses' => $matchingResponses,
                ];
                $correctCount += $isCorrect ? 1 : 0;
            }
        }

        return $this->render('library/quiz_test.html.twig', [
            'quizTemplate' => $template,
            'questions' => $questions,
            'ordreAnswerOrder' => $ordreAnswerOrder,
            'wordBanks' => $wordBanks,
            'zoneChoiceSets' => $zoneChoiceSets,
            'matchingChoiceSets' => $matchingChoiceSets,
            'matchingPairSets' => $matchingPairSets,
            'submitted' => $submitted,
            'results' => $results,
            'correctCount' => $correctCount,
        ]);
    }

    // Screen 1c - launches a copy of $template into a Program (class): builds the QuizInstance
    // snapshot via QuizInstantiationService and hands off to the Program-scoped side
    // (ProgramQuizController). Any teacher who owns/edits the template can launch it into any
    // Program they teach (StructureAccessChecker::isProgramTeacher() at the destination, not
    // just template ownership) - mirrors SequenceLibraryController::instantiate(), except quiz
    // results/instances stay teacher-visible (unlike the ROLE_ADMIN-only séquences Program side),
    // so there's no branching redirect based on role here.
    #[Route(path: '/library/quiz/{id}/launch', name: 'app_library_quiz_launch')]
    public function launch(int $id, Request $request, QuizTemplateRepository $repository, ProgramRepository $programRepository, StructureAccessChecker $accessChecker, QuizInstantiationService $instantiationService): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        $programs = $this->instantiablePrograms($accessChecker, $programRepository);
        // Siblings from the same library, not the viewer's own: staff can open (and launch) another
        // teacher's quiz, and the pool it may be merged with is that teacher's, not theirs.
        $otherTemplates = array_values(array_filter(
            $repository->findForTeacher($template->getTeacher()),
            static fn (QuizTemplate $candidate): bool => $candidate->getId() !== $template->getId(),
        ));
        $form = $this->createForm(QuizLaunchType::class, null, [
            'programs' => $programs,
            'baseTemplateName' => $template->getName(),
            'additionalTemplateChoices' => $otherTemplates,
            'defaultQuestionCount' => min($template->getDefaultQuestionCount(), max(1, $template->getQuestions()->count())),
            'defaultSecondsPerQuestion' => $template->getDefaultSecondsPerQuestion(),
            'defaultSameQuestionsForAll' => $template->isDefaultSameQuestionsForAll(),
            'defaultQuestionOrderPerStudent' => $template->isDefaultQuestionOrderPerStudent(),
            'defaultAnswerOrderPerStudent' => $template->isDefaultAnswerOrderPerStudent(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Program $program */
            $program = $form->get('program')->getData();

            // The launched template first, then the extras in the order the teacher added them -
            // a row left on its placeholder submits null and is simply skipped, and the same quiz
            // picked twice must not double its questions in the pool.
            $templates = [$template];
            /** @var list<QuizTemplate|null> $additional */
            $additional = array_values($form->get('additionalTemplates')->getData() ?? []);
            foreach ($additional as $extra) {
                if ($extra instanceof QuizTemplate && !\in_array($extra, $templates, true)) {
                    $templates[] = $extra;
                }
            }

            $poolSize = array_sum(array_map(static fn (QuizTemplate $item): int => $item->getQuestions()->count(), $templates));

            $instance = $instantiationService->instantiateQuiz(
                templates: $templates,
                program: $program,
                createdBy: $this->currentUser(),
                mode: $form->get('mode')->getData(),
                questionCount: min(FormValue::int($form, 'questionCount'), $poolSize),
                difficultySliderPosition: FormValue::int($form, 'difficultySliderPosition'),
                sameQuestionsForAll: (bool) $form->get('sameQuestionsForAll')->getData(),
                questionOrderPerStudent: (bool) $form->get('questionOrderPerStudent')->getData(),
                answerOrderPerStudent: (bool) $form->get('answerOrderPerStudent')->getData(),
                opensAt: $form->get('opensAt')->getData(),
                closesAt: $form->get('closesAt')->getData(),
                secondsPerQuestion: $form->get('secondsPerQuestion')->getData(),
                globalTimeMinutes: $form->get('globalTimeMinutes')->getData(),
                scoring: $form->get('scoring')->getData(),
                scoreVisibleImmediately: (bool) $form->get('scoreVisibleImmediately')->getData(),
                name: FormValue::trimmed($form, 'name'),
            );

            $this->addFlash('success', 'quizLaunchedFlashMessage');

            return $this->redirectToRoute('app_program_quiz_show', ['id' => $program->getId(), 'instanceId' => $instance->getId()]);
        }

        return $this->render('library/quiz_launch.html.twig', [
            'quizTemplate' => $template,
            'form' => $form,
            // Feeds quiz_pool_controller.js so the pool size, the draw's ceiling and its default
            // all follow the rows without a round trip. The server clamps again on submit.
            'questionCountsByTemplate' => $this->questionCountsByTemplate($otherTemplates),
        ]);
    }

    /**
     * A template's Zone/Légende questions as a downloadable "moncampus-zones/1" document - for
     * sharing between teachers and re-importing through the interactive import (phase 3 of the
     * étude 2026-08-11).
     */
    #[Route(path: '/library/quiz/{id}/export.json', name: 'app_library_quiz_export', methods: ['GET'])]
    public function export(int $id, QuizTemplateRepository $repository, ZoneJsonImporter $zoneImporter): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        $document = $zoneImporter->export($template);
        if ([] === $document['questions']) {
            $this->addFlash('warning', 'zoneExportNothingFlashMessage');

            return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
        }

        $response = new Response((string) json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', 'quiz-zones.json'));

        return $response;
    }

    /**
     * The same door one family over: a template's Apparier questions as a downloadable
     * "moncampus-apparier/1" document. A separate route rather than a parameter on the one above,
     * because the two produce different formats and a single file could not carry both.
     */
    #[Route(path: '/library/quiz/{id}/export/matching.json', name: 'app_library_quiz_export_matching', methods: ['GET'])]
    public function exportMatching(int $id, QuizTemplateRepository $repository, MatchingJsonImporter $matchingImporter): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        $document = $matchingImporter->export($template);
        if ([] === $document['questions']) {
            $this->addFlash('warning', 'matchingExportNothingFlashMessage');

            return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
        }

        $response = new Response((string) json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', 'quiz-apparier.json'));

        return $response;
    }

    #[Route(path: '/library/quiz/{id}/questions', name: 'app_library_quiz_questions')]
    public function questions(int $id, Request $request, QuizTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $canEdit = $this->isGranted(QuizTemplateVoter::EDIT, $template);
        if (!$canEdit) {
            throw $this->createAccessDeniedException();
        }

        $difficultyFilter = '' !== $request->query->get('difficulty', '') ? QuestionDifficulty::tryFrom((string) $request->query->get('difficulty')) : null;
        $typeFilter = '' !== $request->query->get('type', '') ? QuestionType::tryFrom((string) $request->query->get('type')) : null;

        $questions = array_values(array_filter(
            $template->getQuestions()->toArray(),
            static fn (QuizQuestion $question): bool => (null === $difficultyFilter || $question->getEffectiveDifficulty() === $difficultyFilter)
                && (null === $typeFilter || $question->getType() === $typeFilter),
        ));

        $selectedQuestion = null;
        $selectedId = $request->query->getInt('question', 0);
        if ($selectedId > 0) {
            foreach ($template->getQuestions() as $question) {
                if ($question->getId() === $selectedId) {
                    $selectedQuestion = $question;

                    break;
                }
            }
        }
        $selectedQuestion ??= $questions[0] ?? null;

        // The bank is paginated (screen 1b's "Affichage 12 – 16 sur 52"), but the editor on the
        // right must keep showing the selected question - so the page shown by default is the one
        // that question sits on, not page 1. Clicking a question on page 4 must not bounce the list
        // back to page 1 on the next render.
        $page = max(1, $request->query->getInt('page', 0));
        $selectedPosition = null !== $selectedQuestion ? array_search($selectedQuestion, $questions, true) : false;
        if (0 === $request->query->getInt('page', 0) && false !== $selectedPosition) {
            $page = intdiv((int) $selectedPosition, self::QUESTIONS_PER_PAGE) + 1;
        }

        $pageCount = max(1, (int) ceil(\count($questions) / self::QUESTIONS_PER_PAGE));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * self::QUESTIONS_PER_PAGE;
        $pageQuestions = \array_slice($questions, $offset, self::QUESTIONS_PER_PAGE, true);

        $form = null !== $selectedQuestion ? $this->createForm(QuizQuestionType::class, $selectedQuestion) : null;

        return $this->render('library/quiz_questions.html.twig', [
            'quizTemplate' => $template,
            'questions' => $pageQuestions,
            'totalQuestions' => \count($questions),
            'page' => $page,
            'pageCount' => $pageCount,
            'selectedQuestion' => $selectedQuestion,
            'selectedQuestionNumber' => $this->questionNumber($template, $selectedQuestion),
            'form' => $form,
            'difficultyFilter' => $difficultyFilter,
            'typeFilter' => $typeFilter,
            'blank_modes' => BlankMode::cases(),
            'zone_kinds' => ZoneSupportKind::cases(),
            'matching_side_kinds' => MatchingSideKind::cases(),
        ]);
    }

    #[Route(path: '/library/quiz/{id}/questions/new', name: 'app_library_quiz_questions_new', methods: ['POST'])]
    public function questionNew(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);

        if (!$this->isCsrfTokenValid('library_quiz_questions_new', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $question = new QuizQuestion($template);
        $question->setType(QuestionType::Qcm);
        $question->setLabel('');
        $question->setOrderIndex($template->getQuestions()->count() + 1);
        $template->addQuestion($question);

        $entityManager->persist($question);
        $entityManager->flush();

        return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId(), 'question' => $question->getId()]);
    }

    #[Route(path: '/library/quiz/{id}/questions/{questionId}', name: 'app_library_quiz_questions_save', methods: ['POST'])]
    public function questionSave(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);
        $question = $this->findQuestionOrNotFound($questionRepository, $template, $questionId);

        $form = $this->createForm(QuizQuestionType::class, $question);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAnswers($question, $request);
            $this->applyBlanks($question, $request);
            $this->applyZones($question, $request);
            $this->applyMatching($question, $request, $fileUploadService, $matchingImageStore);

            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
            $removeImage = (bool) $form->get('removeImage')->getData();

            if (null !== $imageFile) {
                if (null !== $question->getImageStorageKey()) {
                    $fileUploadService->delete($question->getImageStorageKey());
                }
                $extension = $imageFile->guessExtension() ?? $imageFile->getClientOriginalExtension();
                $key = $fileUploadService->upload(self::IMAGE_UPLOAD_PREFIX, sprintf('%s.%s', bin2hex(random_bytes(16)), $extension), $imageFile);
                $question->setImageStorageKey($key);
            } elseif ($removeImage && null !== $question->getImageStorageKey()) {
                $fileUploadService->delete($question->getImageStorageKey());
                $question->setImageStorageKey(null);
            }

            $template->setLastUpdatedBy($this->currentUser());
            $template->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'quizQuestionUpdatedFlashMessage');

            return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId(), 'question' => $question->getId()]);
        }

        // Re-render after a failed validation: show the page the rejected question is on, so the
        // teacher sees their own row highlighted next to the error rather than an unrelated page 1.
        $questions = $template->getQuestions()->toArray();
        $position = array_search($question, $questions, true);
        $page = false === $position ? 1 : intdiv((int) $position, self::QUESTIONS_PER_PAGE) + 1;

        return $this->render('library/quiz_questions.html.twig', [
            'quizTemplate' => $template,
            'questions' => \array_slice($questions, ($page - 1) * self::QUESTIONS_PER_PAGE, self::QUESTIONS_PER_PAGE, true),
            'totalQuestions' => \count($questions),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($questions) / self::QUESTIONS_PER_PAGE)),
            'selectedQuestion' => $question,
            'selectedQuestionNumber' => $this->questionNumber($template, $question),
            'form' => $form,
            'difficultyFilter' => null,
            'typeFilter' => null,
            'blank_modes' => BlankMode::cases(),
            'zone_kinds' => ZoneSupportKind::cases(),
            'matching_side_kinds' => MatchingSideKind::cases(),
        ]);
    }

    // 1-based position of $question within the template's full, order-index-sorted bank (not the
    // possibly-filtered $questions list passed to the template) - what the mockup's "Question 12"
    // heading and the bank list's left-hand index column both refer to.
    private function questionNumber(QuizTemplate $template, ?QuizQuestion $question): ?int
    {
        if (null === $question) {
            return null;
        }

        foreach (array_values($template->getQuestions()->toArray()) as $index => $candidate) {
            if ($candidate->getId() === $question->getId()) {
                return $index + 1;
            }
        }

        return null;
    }

    #[Route(path: '/library/quiz/{id}/questions/{questionId}/duplicate', name: 'app_library_quiz_questions_duplicate', methods: ['POST'])]
    public function questionDuplicate(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);
        $question = $this->findQuestionOrNotFound($questionRepository, $template, $questionId);

        if (!$this->isCsrfTokenValid('library_quiz_questions_duplicate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $copy = new QuizQuestion($template);
        $copy->setType($question->getType());
        $copy->setDifficulty($question->getDifficulty());
        $copy->setLabel($question->getLabel());
        $copy->setOrderIndex($template->getQuestions()->count() + 1);
        $copy->setBlanksConfig($question->getBlanksConfig());
        $copy->setZoneConfig($question->getZoneConfig());
        $copy->setMatchingConfig($matchingImageStore->copyImages($question->getMatchingConfig()));
        $copy->setPoints($question->getPoints());
        $copy->setExplanation($question->getExplanation());

        if (null !== $question->getImageStorageKey()) {
            $newKey = self::IMAGE_UPLOAD_PREFIX.bin2hex(random_bytes(16)).'.'.pathinfo($question->getImageStorageKey(), \PATHINFO_EXTENSION);
            $fileUploadService->copy($question->getImageStorageKey(), $newKey);
            $copy->setImageStorageKey($newKey);
        }

        foreach ($question->getAnswers() as $answer) {
            $answerCopy = new QuizAnswer($copy);
            $answerCopy->setLabel($answer->getLabel());
            $answerCopy->setIsCorrect($answer->isCorrect());
            $answerCopy->setOrderIndex($answer->getOrderIndex());
            $copy->addAnswer($answerCopy);
        }

        $template->addQuestion($copy);
        $entityManager->persist($copy);
        $entityManager->flush();

        return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId(), 'question' => $copy->getId()]);
    }

    #[Route(path: '/library/quiz/{id}/questions/{questionId}/remove', name: 'app_library_quiz_questions_remove', methods: ['POST'])]
    public function questionRemove(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);
        $question = $this->findQuestionOrNotFound($questionRepository, $template, $questionId);

        if (!$this->isCsrfTokenValid('library_quiz_questions_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (null !== $question->getImageStorageKey()) {
            $fileUploadService->delete($question->getImageStorageKey());
        }
        // An apparier question owns its images inside its config rather than in a column - the
        // store is what knows where to look (App\Service\MatchingImageStore).
        $matchingImageStore->deleteImages($question);

        $entityManager->remove($question);
        $entityManager->flush();

        $this->addFlash('success', 'quizQuestionRemovedFlashMessage');

        return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
    }

    /**
     * The "Tester" tab's reading of a zone/légende submission, question-id-namespaced since every
     * question of the template sits on the one page - and bounded to the question's own config,
     * same as the real passation.
     *
     * @param array<array-key, mixed> $submittedZones
     * @param array<array-key, mixed> $submittedPlacements
     *
     * @return array<array-key, string>
     */
    private function testZoneResponses(QuizQuestion $question, array $submittedZones, array $submittedPlacements): array
    {
        if (QuestionType::Zone === $question->getType()) {
            $raw = $submittedZones[$question->getId()] ?? [];
            $clicked = array_map(strval(...), array_filter(\is_array($raw) ? $raw : [], is_scalar(...)));

            return array_values(array_unique(array_intersect($clicked, $question->getZoneIds())));
        }

        if (QuestionType::Legende === $question->getType()) {
            $raw = $submittedPlacements[$question->getId()] ?? [];
            $choiceKeys = array_column($question->getLegendeChoices(), 'key');
            $zoneIds = $question->getZoneIds();
            $placements = [];
            foreach (\is_array($raw) ? $raw : [] as $zoneId => $key) {
                if (\is_scalar($key) && \in_array((string) $zoneId, $zoneIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                    $placements[(string) $zoneId] = (string) $key;
                }
            }

            return $placements;
        }

        return [];
    }

    /**
     * The "Tester" tab's reading of an apparier submission, question-id-namespaced for the same
     * reason as testZoneResponses() and bounded to the question's own pairs and choices.
     *
     * @param array<array-key, mixed> $submittedPairs
     *
     * @return array<string, string>
     */
    private function testMatchingResponses(QuizQuestion $question, array $submittedPairs): array
    {
        if (QuestionType::Apparier !== $question->getType()) {
            return [];
        }

        $raw = $submittedPairs[$question->getId()] ?? [];
        $choiceKeys = array_column($question->getMatchingChoices(), 'key');
        $pairIds = $question->getMatchingPairIds();
        $associations = [];
        foreach (\is_array($raw) ? $raw : [] as $pairId => $key) {
            if (\is_scalar($key) && \in_array((string) $pairId, $pairIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                $associations[(string) $pairId] = (string) $key;
            }
        }

        return $associations;
    }

    /**
     * The answers reduced to what grading needs, so the preview goes through the very same
     * QuizAnswerChecker a real attempt does - the two rules used to be written out twice, with only
     * a comment promising they matched.
     *
     * @return list<array{id: int, correct: bool, orderIndex: int}>
     */
    private function answerRows(QuizQuestion $question): array
    {
        return array_values(array_map(
            static fn (QuizAnswer $answer): array => [
                'id' => $answer->getId(),
                'correct' => $answer->isCorrect(),
                'orderIndex' => $answer->getOrderIndex(),
            ],
            $question->getAnswers()->toArray(),
        ));
    }

    // Resolves the dynamic answers[N][label]/answers[N][correct] rows submitted alongside the
    // QuizQuestionType form (see that class's docblock for why they aren't real form fields) into
    // QuizAnswer entities. Replaces the answers collection wholesale rather than diffing, same
    // reasoning as SequenceLibraryController::applyTags() for the blocs collection.
    /**
     * Screens 2a/2b - the texte à trous definition, submitted as blanks[...] fields by
     * assets/controllers/quiz_blanks_editor_controller.js. Answers are re-indexed against the blank
     * count the *statement* has after this save, never against what the client posted: the client's
     * row set is only as fresh as its last keystroke, and a stale extra row must not become a
     * phantom fourth blank (see App\Entity\QuizQuestionDefinitionTrait).
     */
    private function applyBlanks(QuizQuestion $question, Request $request): void
    {
        if (QuestionType::TexteATrous !== $question->getType()) {
            // Switching a question away from texte à trous leaves the old config behind on purpose:
            // switching back restores the blanks the teacher had already written.
            return;
        }

        $submitted = $request->request->all('blanks');
        $mode = $submitted['mode'] ?? null;
        $points = $submitted['points'] ?? null;

        $question->setBlankMode(BlankMode::tryFrom(\is_scalar($mode) ? (string) $mode : '') ?? BlankMode::Banque);
        $question->setIgnoreCase(isset($submitted['ignoreCase']));
        $question->setTolerateTypo(isset($submitted['tolerateTypo']));
        $question->setPoints(max(0.25, is_numeric($points) ? (float) $points : 1.0));

        $postedAnswers = \is_array($submitted['answers'] ?? null) ? $submitted['answers'] : [];
        $answers = [];
        for ($i = 0, $blankCount = $question->getBlankCount(); $i < $blankCount; ++$i) {
            $variants = $postedAnswers[$i] ?? [];
            $answers[] = array_values(array_map(strval(...), \is_array($variants) ? $variants : [$variants]));
        }
        $question->setBlankAnswers($answers);

        $distractors = $submitted['distractors'] ?? [];
        $question->setDistractors(array_values(array_map(strval(...), \is_array($distractors) ? $distractors : [])));
    }

    /**
     * The Zone/Légende definition, submitted as raw zones[...] fields by
     * assets/controllers/quiz_zone_editor_controller.js. Correct/hint/labels/feedback are bounded
     * to the zones the support has *after this save* - the client's row set is only as fresh as
     * its last keystroke, exactly like applyBlanks() one type over. The per-question markers of an
     * imported question are preserved: the editor has no UI for them, and dropping them would
     * silently unmark every zone of a support that needed the override.
     */
    private function applyZones(QuizQuestion $question, Request $request): void
    {
        if (!$question->getType()->usesZoneConfig()) {
            // Switching a question away from zone/légende leaves the old config behind on purpose:
            // switching back restores the zones the teacher had already marked.
            return;
        }

        $submitted = $request->request->all('zones');
        $previous = $question->getZoneConfig() ?? [];

        $kind = ZoneSupportKind::tryFrom(\is_scalar($submitted['kind'] ?? null) ? (string) $submitted['kind'] : '') ?? ZoneSupportKind::Texte;
        $config = ['kind' => $kind->value];

        if (isset($previous['markers']) && \is_array($previous['markers'])) {
            $config['markers'] = $previous['markers'];
        }

        if (ZoneSupportKind::Image === $kind) {
            $rawZones = $submitted['imageZones'] ?? null;
            $decoded = \is_scalar($rawZones) ? json_decode((string) $rawZones, true) : null;
            $config['zones'] = \is_array($decoded) ? $decoded : [];
        } else {
            $config['content'] = \is_scalar($submitted['content'] ?? null) ? (string) $submitted['content'] : '';
            $language = \is_scalar($submitted['language'] ?? null) ? trim((string) $submitted['language']) : '';
            if (ZoneSupportKind::Code === $kind && '' !== $language) {
                $config['language'] = $language;
            }
        }

        // Bound every id-carrying field to the support's real zones. setZoneConfig() first so the
        // accessors read the new support, then re-set with the cleaned lists.
        $question->setZoneConfig($config);
        $zoneIds = $question->getZoneIds();

        $cleanIds = static fn (mixed $list): array => \is_array($list)
            ? array_values(array_intersect(array_map(strval(...), array_filter($list, is_scalar(...))), $zoneIds))
            : [];
        $cleanMap = static function (mixed $map, array $allowedKeys) {
            $clean = [];
            foreach (\is_array($map) ? $map : [] as $key => $text) {
                if (\in_array((string) $key, $allowedKeys, true) && \is_scalar($text) && '' !== trim((string) $text)) {
                    $clean[(string) $key] = trim((string) $text);
                }
            }

            return $clean;
        };

        if (QuestionType::Zone === $question->getType()) {
            $config['correct'] = $cleanIds($submitted['correct'] ?? null);
            $config['hint'] = $cleanIds($submitted['hint'] ?? null);
            $config['feedback'] = $cleanMap($submitted['feedback'] ?? null, [...$zoneIds, '*']);
        } else {
            $config['labels'] = $cleanMap($submitted['labels'] ?? null, $zoneIds);
            $distractorsText = \is_scalar($submitted['distractors_text'] ?? null) ? (string) $submitted['distractors_text'] : '';
            $config['distractors'] = array_values(array_filter(array_map(trim(...), explode("\n", $distractorsText))));
        }

        $question->setZoneConfig($config);

        $points = $submitted['points'] ?? null;
        $question->setPoints(max(0.25, is_numeric($points) ? (float) $points : 1.0));
    }

    /**
     * The Apparier definition, submitted as raw matching[...] fields by
     * assets/controllers/quiz_matching_editor_controller.js. Each row carries its own id so that
     * reordering the pairs never moves a feedback onto the wrong one; a row that arrives without a
     * usable id (a brand new one) is given the first free "pN", and a duplicate id is renamed the
     * same way rather than silently swallowing the earlier row.
     */
    private function applyMatching(QuizQuestion $question, Request $request, FileUploadService $fileUploadService, MatchingImageStore $matchingImageStore): void
    {
        if (!$question->getType()->usesMatchingConfig()) {
            // Switching a question away from apparier leaves the old config behind on purpose:
            // switching back restores the pairs the teacher had already written, exactly like
            // applyBlanks()/applyZones() do for their own types.
            return;
        }

        $submitted = $request->request->all('matching');
        // Narrowed once, here: everything below indexes into these, and Symfony's FileBag hands
        // back a nested array of whatever the client posted.
        $files = $request->files->all()['matching'] ?? [];
        $files = \is_array($files) ? $files : [];
        $pairFiles = \is_array($files['pairs'] ?? null) ? $files['pairs'] : [];
        $distractorFiles = \is_array($files['distractorFiles'] ?? null) ? $files['distractorFiles'] : [];
        $stringOf = static fn (mixed $value): string => \is_scalar($value) ? trim((string) $value) : '';

        // Every key the question owned before this save. Whatever is still referenced afterwards is
        // subtracted, and the remainder is deleted - which is how replacing a photo, emptying a row
        // or switching a column back to text all reclaim their objects through one rule.
        $previousKeys = $question->getMatchingImageKeys();

        $config = [];
        $leftHeader = $stringOf($submitted['leftHeader'] ?? null);
        $rightHeader = $stringOf($submitted['rightHeader'] ?? null);
        if ('' !== $leftHeader) {
            $config['leftHeader'] = $leftHeader;
        }
        if ('' !== $rightHeader) {
            $config['rightHeader'] = $rightHeader;
        }

        $leftKind = MatchingSideKind::tryFrom($stringOf($submitted['leftKind'] ?? null)) ?? MatchingSideKind::Texte;
        $rightKind = MatchingSideKind::tryFrom($stringOf($submitted['rightKind'] ?? null)) ?? MatchingSideKind::Texte;
        $config['leftKind'] = $leftKind->value;
        $config['rightKind'] = $rightKind->value;

        $pairs = [];
        $feedback = [];
        $usedIds = [];
        $nextId = 1;
        foreach (\is_array($submitted['pairs'] ?? null) ? $submitted['pairs'] : [] as $rowKey => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $left = $stringOf($row['left'] ?? null);
            $right = $stringOf($row['right'] ?? null);
            $rowFiles = \is_array($pairFiles[$rowKey] ?? null) ? $pairFiles[$rowKey] : [];
            // An uploaded file wins over the key already on the row - that is what "replace this
            // photo" means; the old key falls out of $keptKeys below and gets deleted.
            $leftImage = $this->uploadedMatchingImage($rowFiles['leftFile'] ?? null, $fileUploadService) ?? $stringOf($row['leftImage'] ?? null);
            $rightImage = $this->uploadedMatchingImage($rowFiles['rightFile'] ?? null, $fileUploadService) ?? $stringOf($row['rightImage'] ?? null);

            // A row emptied out in the editor is a deleted row, not a broken pair - "emptied" means
            // nothing left on either side, image included.
            if ('' === $left && '' === $right && '' === $leftImage && '' === $rightImage) {
                continue;
            }

            $id = $stringOf($row['id'] ?? null);
            if ('' === $id || isset($usedIds[$id])) {
                while (isset($usedIds['p'.$nextId])) {
                    ++$nextId;
                }
                $id = 'p'.$nextId;
            }
            $usedIds[$id] = true;

            $pair = ['id' => $id, 'left' => $left, 'right' => $right];
            // Keys are stored only for the column that is an image one: a column switched back to
            // text drops its keys here, which is exactly what makes them deletable below.
            if ($leftKind->isImage() && '' !== $leftImage) {
                $pair['leftImage'] = $leftImage;
            }
            if ($rightKind->isImage() && '' !== $rightImage) {
                $pair['rightImage'] = $rightImage;
            }
            $pairs[] = $pair;

            $rowFeedback = $stringOf($row['feedback'] ?? null);
            if ('' !== $rowFeedback) {
                $feedback[$id] = $rowFeedback;
            }
        }
        $config['pairs'] = $pairs;

        $wildcard = $stringOf($submitted['feedbackDefault'] ?? null);
        if ('' !== $wildcard) {
            $feedback['*'] = $wildcard;
        }
        if ([] !== $feedback) {
            $config['feedback'] = $feedback;
        }

        // A distractor repeating one of the real answers is dropped, same rule and same reason as
        // in App\Service\MatchingJsonImporter: grading compares what a choice *is*, so it would be
        // accepted as correct anyway and only takes up room as a decoy.
        $rights = array_column($pairs, 'right');
        $distractorsText = \is_scalar($submitted['distractors_text'] ?? null) ? (string) $submitted['distractors_text'] : '';
        $config['distractors'] = array_values(array_unique(array_filter(
            array_map(trim(...), explode("\n", $distractorsText)),
            static fn (string $text): bool => '' !== $text && !\in_array($text, $rights, true),
        )));

        if ($rightKind->isImage()) {
            // The decoys the editor kept (each has a ✕ that removes its hidden input), plus
            // whatever was just uploaded. Same "repeats a real answer" rule, on keys this time.
            $usedImages = array_values(array_filter(array_map(
                static fn (array $pair): string => $stringOf($pair['rightImage'] ?? null),
                $pairs,
            )));
            $kept = array_map($stringOf, array_filter((array) ($submitted['distractorImages'] ?? []), is_scalar(...)));
            foreach ($distractorFiles as $file) {
                $uploaded = $this->uploadedMatchingImage($file, $fileUploadService);
                if (null !== $uploaded) {
                    $kept[] = $uploaded;
                }
            }
            $config['distractorImages'] = array_values(array_unique(array_filter(
                $kept,
                static fn (string $key): bool => '' !== $key && !\in_array($key, $usedImages, true),
            )));
        }

        $question->setMatchingConfig($config);

        // Read back through the accessors rather than off $config: they are what decides which
        // keys the question actually owns now, so an orphan can never be an accounting mistake.
        $matchingImageStore->deleteKeys(array_values(array_diff($previousKeys, $question->getMatchingImageKeys())));

        $points = $submitted['points'] ?? null;
        $question->setPoints(max(0.25, is_numeric($points) ? (float) $points : 1.0));
    }

    /** Stores one just-uploaded pair/decoy image and returns its key, or null when nothing was sent. */
    private function uploadedMatchingImage(mixed $file, FileUploadService $fileUploadService): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();

        return $fileUploadService->upload(
            MatchingImageStore::UPLOAD_PREFIX,
            sprintf('%s.%s', bin2hex(random_bytes(16)), $extension),
            $file,
        );
    }

    private function applyAnswers(QuizQuestion $question, Request $request): void
    {
        foreach ($question->getAnswers()->toArray() as $answer) {
            $question->removeAnswer($answer);
        }

        // A texte à trous keeps no QuizAnswer rows at all - the clearing above is what makes
        // switching a question over to that type drop the options it used to have.
        if (!$question->getType()->usesAnswerRows()) {
            return;
        }

        $rows = $request->request->all('answers');
        $orderIndex = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rawLabel = $row['label'] ?? null;
            $label = \is_scalar($rawLabel) ? trim((string) $rawLabel) : '';
            if ('' === $label) {
                continue;
            }

            $answer = new QuizAnswer($question);
            $answer->setLabel($label);
            $answer->setIsCorrect('1' === ($row['correct'] ?? null));
            $answer->setOrderIndex($orderIndex++);
            $question->addAnswer($answer);
        }
    }

    /** @return array{id: int, name: string, subject: string, questionCount: int, difficultyLabel: string, difficultyDots: int, updatedAt: string} */
    private function rowForTemplate(QuizTemplate $template, TranslatorInterface $translator): array
    {
        $difficulties = array_map(
            static fn (QuizQuestion $question): QuestionDifficulty => $question->getEffectiveDifficulty(),
            $template->getQuestions()->toArray(),
        );
        $hasFacile = \in_array(QuestionDifficulty::Facile, $difficulties, true);
        $hasDifficile = \in_array(QuestionDifficulty::Difficile, $difficulties, true);

        if ([] === $difficulties || (!$hasFacile && !$hasDifficile)) {
            $summary = QuestionDifficulty::Moyen;
        } elseif ($hasFacile && !$hasDifficile) {
            $summary = QuestionDifficulty::Facile;
        } elseif (!$hasFacile) {
            // Difficile is implied: the branches above already ruled out "neither" and "Facile only".
            $summary = QuestionDifficulty::Difficile;
        } else {
            $summary = null; // mixte - no single QuestionDifficulty case fits, handled below
        }

        $updatedAt = $template->getLastUpdatedDate() ?? $template->getCreationDate();

        return [
            'id' => $template->getId(),
            'name' => $template->getName() ?? '',
            'subject' => $template->getSubject() ?? '—',
            'questionCount' => $template->getQuestions()->count(),
            'difficultyLabel' => null !== $summary ? $translator->trans($summary->labelKey()) : $translator->trans('quizTemplateDifficultyMixedLabel'),
            'difficultyDots' => null !== $summary ? $summary->dotCount() : 2,
            'updatedAt' => $updatedAt->format('d/m/Y'),
        ];
    }

    // Programs the launching teacher actually teaches (or every active, visible Program, for
    // staff) - unlike SequenceLibraryController::instantiablePrograms(), no
    // timetableManagementEnabled filter: launching a quiz doesn't depend on the timetable feature
    // at all. Reconciled onto findActiveForNav() (was raw findAll(), which both ignored
    // Program::$visibility and included inactive Programs) so quiz launch respects the same
    // visibility tiering as every other Program-audience picker.
    /** @return list<Program> */
    private function instantiablePrograms(StructureAccessChecker $accessChecker, ProgramRepository $programRepository): array
    {
        return $accessChecker->isStaff()
            ? $programRepository->findActiveForNav($this->currentUser())
            : $programRepository->findAllForTeacher($this->currentUser());
    }

    /**
     * The two numbers quiz_pool_controller.js needs per template: how many questions it brings to
     * the pool, and how many it draws by default - the second is summed so that merging quizzes
     * widens the draw instead of leaving it on the first quiz's own default.
     *
     * @param list<QuizTemplate> $templates
     *
     * @return array<int, array{questions: int, defaultCount: int}>
     */
    private function questionCountsByTemplate(array $templates): array
    {
        $counts = [];
        foreach ($templates as $template) {
            $counts[(int) $template->getId()] = [
                'questions' => $template->getQuestions()->count(),
                'defaultCount' => min($template->getDefaultQuestionCount(), $template->getQuestions()->count()),
            ];
        }

        return $counts;
    }

    private function findTemplateOrNotFound(QuizTemplateRepository $repository, int $id): QuizTemplate
    {
        $template = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($template->getTeacher() !== $this->currentUser() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_STAFF-LEAD')) {
            throw $this->createNotFoundException();
        }

        return $template;
    }

    private function findQuestionOrNotFound(QuizQuestionRepository $repository, QuizTemplate $template, int $id): QuizQuestion
    {
        $question = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($question->getQuizTemplate()->getId() !== $template->getId()) {
            throw $this->createNotFoundException();
        }

        return $question;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
