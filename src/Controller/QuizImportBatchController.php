<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\QuizSourceScope;
use App\Repository\QuizFolderRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\InteractiveQuizImporterRegistry;
use App\Service\PostValue;
use App\Service\QuizImportImages;
use App\Service\QuizImportPreview;
use App\Service\QuizImportSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Step 4 when the paste - or the archive - held **several** quizzes: one verification screen for
 * the whole batch, and one confirmation that creates them all.
 *
 * A controller of its own rather than a branch inside App\Controller\QuizImportController::preview(),
 * because almost nothing of that screen survives the plural. Its one decision is *where the
 * questions land* - a new quiz, or an existing bank being built in several goes - and neither half
 * of that question has an answer for a dozen documents at once: they are a dozen quizzes, and
 * merging them into one existing bank is the opposite of what a batch is for. What is left in
 * common is the payload shape, the transient preview (App\Service\QuizImportPreview) and the
 * « rattacher à la séance … » offer, and all three are shared rather than copied.
 *
 * The rail is what makes it a screen rather than a scroll: one entry per quiz, the current one
 * marked, and its panel below - switched in the browser, since the verification is a reading and a
 * round trip per quiz would be a round trip per reading. Every panel's fields are in the one form,
 * so the batch is confirmed whole, in the order the documents arrived.
 *
 * Nothing is written before that confirmation, exactly as on the single-quiz screen.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::QuizLibrary)]
class QuizImportBatchController extends AbstractController
{
    #[Route(path: '/library/quiz/import/batch', name: 'app_library_quiz_import_batch', methods: ['GET'])]
    public function preview(
        Request $request,
        InteractiveQuizImporterRegistry $registry,
        QuizImportPreview $preview,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): Response {
        $batch = $this->batch($request);
        if ([] === $batch) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute('app_library_quiz_assistant_paste');
        }

        $quizzes = [];
        foreach ($batch as $index => $payload) {
            $rows = $preview->of($payload, $this->currentUser());
            $quizzes[] = [
                'index' => $index,
                'payload' => $payload,
                'family' => $registry->forPayloadFormat($payload['format'])?->family(),
                'questions' => $rows['questions'],
                'gaps' => $rows['gaps'],
                'incompleteCount' => $rows['incompleteCount'],
            ];
        }

        $attachTo = $this->attachmentTarget($request, $sequenceRepository, $seanceRepository);

        return $this->render('library/quiz_import_batch_preview.html.twig', [
            'quizzes' => $quizzes,
            'questionTotal' => array_sum(array_map(static fn (array $one): int => \count($one['questions']), $quizzes)),
            'attachTo' => null === $attachTo ? null : [
                'scope' => $attachTo instanceof SeanceTemplate ? QuizSourceScope::Seance->value : QuizSourceScope::Sequence->value,
                'title' => (string) $attachTo->getTitre(),
            ],
        ]);
    }

    /**
     * The one write of the whole batch. Every quiz is created and filled in the order the documents
     * arrived, then flushed once - a batch is a single gesture, and half of it landing because the
     * fourth document tripped a constraint is not a state the teacher could make sense of.
     */
    #[Route(path: '/library/quiz/import/batch/confirm', name: 'app_library_quiz_import_batch_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $entityManager,
        InteractiveQuizImporterRegistry $registry,
        QuizFolderRepository $folders,
        QuizImportImages $images,
        SequenceTemplateRepository $sequenceRepository,
        SeanceTemplateRepository $seanceRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('quiz_import_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $batch = $this->batch($request);
        if ([] === $batch) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute('app_library_quiz_assistant_paste');
        }

        $folder = $this->rememberedFolder($request, $folders);
        $attachTo = $this->attachmentTarget($request, $sequenceRepository, $seanceRepository);
        $attach = null !== $attachTo && $request->request->getBoolean('attach');

        /** @var array<array-key, mixed> $names */
        $names = PostValue::all($request, 'names');
        /** @var array<array-key, mixed> $subjects */
        $subjects = PostValue::all($request, 'subjects');
        /** @var array<array-key, mixed> $descriptions */
        $descriptions = PostValue::all($request, 'descriptions');
        /** @var array<array-key, mixed> $included */
        $included = PostValue::all($request, 'include');

        $created = 0;
        foreach ($batch as $index => $payload) {
            // A checkbox that is not ticked submits nothing at all, so the batch's own indexes are
            // what says which quizzes were kept - never the position in the posted arrays.
            if (!isset($included[$index])) {
                continue;
            }

            $template = new QuizTemplate($this->currentUser());
            $template->setFolder($folder);
            $template->setName($this->line($names[$index] ?? null) ?? $payload['name']);
            $template->setSubject($this->line($subjects[$index] ?? null) ?? $payload['subject']);
            $template->setDescription($this->line($descriptions[$index] ?? null) ?? $payload['description']);
            $template->setCreatedBy($this->currentUser());
            // Same adjustment as the single-quiz screen: a freshly imported bank is usually smaller
            // than the 20-question default draw, and a draw larger than its bank is refused at launch.
            $template->setDefaultQuestionCount(min($template->getDefaultQuestionCount(), \count($payload['questions'])));

            $registry->forPayloadFormat($payload['format'])?->appendQuestions($template, $payload['questions']);

            if ($attach) {
                if ($attachTo instanceof SeanceTemplate) {
                    $template->addSeanceTemplate($attachTo);
                } elseif (null !== $attachTo) {
                    $template->addSequenceTemplate($attachTo);
                }
            }

            $entityManager->persist($template);
            ++$created;
        }

        if (0 === $created) {
            $this->addFlash('warning', 'quizBatchNothingSelectedFlashMessage');

            return $this->redirectToRoute('app_library_quiz_import_batch');
        }

        $entityManager->flush();

        $request->getSession()->remove(QuizImportSession::BATCH_KEY);
        $request->getSession()->remove(QuizImportSession::SOURCE_KEY);
        $request->getSession()->remove(QuizImportSession::FOLDER_KEY);
        $images->clear();

        $this->addFlash('success', $this->translatedCount($created));

        return $this->redirectToRoute(
            $folder instanceof QuizFolder ? 'app_library_quiz_folder' : 'app_library_quiz',
            $folder instanceof QuizFolder ? ['folderId' => $folder->getId()] : [],
        );
    }

    /**
     * The batch waiting in the session, or an empty list - an expired session, or a teacher who
     * walked back onto the paste screen, which clears it.
     *
     * @return list<array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>}>
     */
    private function batch(Request $request): array
    {
        $batch = $request->getSession()->get(QuizImportSession::BATCH_KEY);
        if (!\is_array($batch)) {
            return [];
        }

        $payloads = [];
        foreach ($batch as $payload) {
            if (\is_array($payload) && \is_array($payload['questions'] ?? null) && [] !== $payload['questions']) {
                /* @var array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>} $payload */
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    private function translatedCount(int $created): string
    {
        return 1 === $created ? 'quizImportCreatedFlashMessage' : 'quizBatchCreatedFlashMessage';
    }

    /** A submitted field, trimmed, or null when it was left empty - which means "keep the document's own". */
    private function line(mixed $value): ?string
    {
        $line = \is_scalar($value) ? trim((string) $value) : '';

        return '' === $line ? null : $line;
    }

    /**
     * The séance or séquence the whole batch may be attached to, read off the session key the paste
     * screen wrote. Re-checked here rather than trusted from one request earlier, exactly as on the
     * single-quiz screen: a séquence can change hands - or be deleted - in between.
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

    /** Where the import was started from, verified here because the id was written at a door that did not check it. */
    private function rememberedFolder(Request $request, QuizFolderRepository $folders): ?QuizFolder
    {
        $folderId = $request->getSession()->get(QuizImportSession::FOLDER_KEY);
        if (!\is_int($folderId)) {
            return null;
        }

        $folder = $folders->find($folderId);

        return $folder instanceof QuizFolder && $folder->getOwner() === $this->currentUser() ? $folder : null;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
