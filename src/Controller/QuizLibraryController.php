<?php

namespace App\Controller;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Form\QuizQuestionType;
use App\Form\QuizTemplateSettingsType;
use App\Repository\QuizQuestionRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\FileUploadService;
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
// quiz" section and reference/Générateur de quiz.dc.html (screens 1a/1b/1n). Deliberately not
// Program-scoped, exactly like SequenceLibraryController: QuizTemplate/QuizQuestion/QuizAnswer are
// owned by a teacher, browsable only by that teacher (or staff, via QuizTemplateVoter::EDIT).
// Launching a template against a real class (QuizInstance) is a separate, Program-scoped
// controller landing in a later phase - see App\Entity\QuizTemplate's class docblock.
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class QuizLibraryController extends AbstractController
{
    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

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

    // "+ Nouveau quiz" (1a) creates a blank template immediately and drops the teacher straight
    // into the question editor (1b) - there's no separate "create" form screen in the mockups,
    // renaming/describing it happens via the Paramètres tab (1n) exactly like an existing template.
    #[Route(path: '/library/quiz/new', name: 'app_library_quiz_new', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('library_quiz_new', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $template = new QuizTemplate($this->currentUser());
        $template->setName($translator->trans('quizTemplateDefaultNewName'));
        $template->setCreatedBy($this->currentUser());

        $entityManager->persist($template);
        $entityManager->flush();

        return $this->redirectToRoute('app_library_quiz_questions', ['id' => $template->getId()]);
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

        $form = null !== $selectedQuestion ? $this->createForm(QuizQuestionType::class, $selectedQuestion) : null;

        return $this->render('library/quiz_questions.html.twig', [
            'quizTemplate' => $template,
            'questions' => $questions,
            'selectedQuestion' => $selectedQuestion,
            'selectedQuestionNumber' => $this->questionNumber($template, $selectedQuestion),
            'form' => $form,
            'difficultyFilter' => $difficultyFilter,
            'typeFilter' => $typeFilter,
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

        $questions = $template->getQuestions()->toArray();

        return $this->render('library/quiz_questions.html.twig', [
            'quizTemplate' => $template,
            'questions' => $questions,
            'selectedQuestion' => $question,
            'selectedQuestionNumber' => $this->questionNumber($template, $question),
            'form' => $form,
            'difficultyFilter' => null,
            'typeFilter' => null,
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

    // Resolves the dynamic answers[N][label]/answers[N][correct] rows submitted alongside the
    // QuizQuestionType form (see that class's docblock for why they aren't real form fields) into
    // QuizAnswer entities. Replaces the answers collection wholesale rather than diffing, same
    // reasoning as SequenceLibraryController::applyTags() for the blocs collection.
    private function applyAnswers(QuizQuestion $question, Request $request): void
    {
        foreach ($question->getAnswers()->toArray() as $answer) {
            $question->removeAnswer($answer);
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
