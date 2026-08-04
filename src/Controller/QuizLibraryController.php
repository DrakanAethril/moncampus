<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\BlankMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Form\QuizLaunchType;
use App\Form\QuizQuestionType;
use App\Form\QuizTemplateSettingsType;
use App\Repository\ProgramRepository;
use App\Repository\QuizQuestionRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\FileUploadService;
use App\Service\QuizInstantiationService;
use App\Util\BlankTextParser;
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
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, FileUploadService $fileUploadService, TranslatorInterface $translator): JsonResponse
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
    public function remove(int $id, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, FileUploadService $fileUploadService): JsonResponse
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
    public function test(int $id, Request $request, QuizTemplateRepository $repository): Response
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
        }

        if ($submitted) {
            $submittedAnswers = $request->query->all('answers');
            $submittedBlanks = $request->query->all('blanks');
            $correctCount = 0;

            foreach ($questions as $question) {
                $selectedIds = array_map(intval(...), $submittedAnswers[$question->getId()] ?? []);
                $blankResponses = array_map(strval(...), $submittedBlanks[$question->getId()] ?? []);
                $isCorrect = $this->isTestAnswerCorrect($question, $selectedIds, $blankResponses);
                $results[$question->getId()] = ['isCorrect' => $isCorrect, 'blankResponses' => $blankResponses];
                $correctCount += $isCorrect ? 1 : 0;
            }
        }

        return $this->render('library/quiz_test.html.twig', [
            'quizTemplate' => $template,
            'questions' => $questions,
            'ordreAnswerOrder' => $ordreAnswerOrder,
            'wordBanks' => $wordBanks,
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
        $form = $this->createForm(QuizLaunchType::class, null, [
            'programs' => $programs,
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

            $instance = $instantiationService->instantiateQuiz(
                template: $template,
                program: $program,
                createdBy: $this->currentUser(),
                mode: $form->get('mode')->getData(),
                questionCount: min((int) $form->get('questionCount')->getData(), $template->getQuestions()->count()),
                difficultySliderPosition: (int) $form->get('difficultySliderPosition')->getData(),
                sameQuestionsForAll: (bool) $form->get('sameQuestionsForAll')->getData(),
                questionOrderPerStudent: (bool) $form->get('questionOrderPerStudent')->getData(),
                answerOrderPerStudent: (bool) $form->get('answerOrderPerStudent')->getData(),
                opensAt: $form->get('opensAt')->getData(),
                closesAt: $form->get('closesAt')->getData(),
                secondsPerQuestion: $form->get('secondsPerQuestion')->getData(),
                globalTimeMinutes: $form->get('globalTimeMinutes')->getData(),
                scoring: $form->get('scoring')->getData(),
                scoreVisibleImmediately: (bool) $form->get('scoreVisibleImmediately')->getData(),
            );

            $this->addFlash('success', 'quizLaunchedFlashMessage');

            return $this->redirectToRoute('app_program_quiz_show', ['id' => $program->getId(), 'instanceId' => $instance->getId()]);
        }

        return $this->render('library/quiz_launch.html.twig', [
            'quizTemplate' => $template,
            'form' => $form,
        ]);
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
    public function questionSave(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $template);
        $question = $this->findQuestionOrNotFound($questionRepository, $template, $questionId);

        $form = $this->createForm(QuizQuestionType::class, $question);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAnswers($question, $request);
            $this->applyBlanks($question, $request);

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
    public function questionDuplicate(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService): Response
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
    public function questionRemove(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, QuizTemplateRepository $repository, QuizQuestionRepository $questionRepository, FileUploadService $fileUploadService): Response
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

        $entityManager->remove($question);
        $entityManager->flush();

        $this->addFlash('success', 'quizQuestionRemovedFlashMessage');

        return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
    }

    // Grading rules for the "Tester" tab (test()) - mirrors App\Service\QuizAttemptGrader::isCorrect()
    // exactly, but on QuizQuestion/QuizAnswer instead of QuizInstanceQuestion/QuizInstanceAnswer.
    /** @param list<int> $selectedAnswerIds in submission order (order only matters for "ordre" questions) */
    /** @param list<string> $blankResponses */
    private function isTestAnswerCorrect(QuizQuestion $question, array $selectedAnswerIds, array $blankResponses = []): bool
    {
        return match ($question->getType()) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image => $this->isTestAnswerCorrectSingle($question, $selectedAnswerIds),
            QuestionType::QcmMulti => $this->isTestAnswerCorrectMulti($question, $selectedAnswerIds),
            QuestionType::Ordre => $this->isTestAnswerCorrectOrder($question, $selectedAnswerIds),
            QuestionType::TexteATrous => $this->isTestAnswerCorrectBlanks($question, $blankResponses),
        };
    }

    /**
     * The preview counts a texte à trous the same all-or-nothing way it counts every other type -
     * this tab answers "does my question work?", not "what would a student score?", so the partial
     * credit of a real attempt would only be noise here.
     *
     * @param list<string> $responses
     */
    private function isTestAnswerCorrectBlanks(QuizQuestion $question, array $responses): bool
    {
        $variantsPerBlank = $question->getBlankAnswers();
        if ([] === $variantsPerBlank) {
            return false;
        }

        foreach ($variantsPerBlank as $index => $variants) {
            if ([] === $variants || !BlankTextParser::matches($responses[$index] ?? '', $variants, $question->isIgnoreCase(), $question->isTolerateTypo())) {
                return false;
            }
        }

        return true;
    }

    private function isTestAnswerCorrectSingle(QuizQuestion $question, array $selectedIds): bool
    {
        if (1 !== \count($selectedIds)) {
            return false;
        }

        $correctId = $this->correctTestAnswerIds($question)[0] ?? null;

        return null !== $correctId && $selectedIds[0] === $correctId;
    }

    private function isTestAnswerCorrectMulti(QuizQuestion $question, array $selectedIds): bool
    {
        $correctIds = $this->correctTestAnswerIds($question);
        if ([] === $correctIds) {
            return false;
        }

        sort($selectedIds);
        sort($correctIds);

        return $selectedIds === $correctIds;
    }

    private function isTestAnswerCorrectOrder(QuizQuestion $question, array $selectedIds): bool
    {
        $answers = $question->getAnswers()->toArray();
        usort($answers, static fn (QuizAnswer $a, QuizAnswer $b): int => $a->getOrderIndex() <=> $b->getOrderIndex());
        $correctSequence = array_map(static fn (QuizAnswer $a): int => $a->getId(), $answers);

        return $selectedIds === $correctSequence;
    }

    /** @return list<int> */
    private function correctTestAnswerIds(QuizQuestion $question): array
    {
        return array_values(array_map(
            static fn (QuizAnswer $a): int => $a->getId(),
            array_filter($question->getAnswers()->toArray(), static fn (QuizAnswer $a): bool => $a->isCorrect()),
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

        $question->setBlankMode(BlankMode::tryFrom((string) ($submitted['mode'] ?? '')) ?? BlankMode::Banque);
        $question->setIgnoreCase(isset($submitted['ignoreCase']));
        $question->setTolerateTypo(isset($submitted['tolerateTypo']));
        $question->setPoints(max(0.25, (float) ($submitted['points'] ?? 1)));

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
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue;
            }

            $answer = new QuizAnswer($question);
            $answer->setLabel($label);
            $answer->setIsCorrect('1' === (string) ($row['correct'] ?? ''));
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
        } elseif ($hasDifficile && !$hasFacile) {
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
