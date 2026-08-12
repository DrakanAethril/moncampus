<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Entity\VideoCuePoint;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Enum\QuestionType;
use App\Form\QuizImportType;
use App\Repository\QuizQuestionRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\VideoCueAnswerRepository;
use App\Repository\VideoCuePointRepository;
use App\Repository\VideoResourceRepository;
use App\Security\StructureAccessChecker;
use App\Service\JsonRequestPayload;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\VideoImportContext;
use App\Service\VideoResourceAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The teacher's half of the interactive video (créas 5B, screens 3, 3 bis and 5): posting questions
 * on a timeline, importing them from a CSV, and reading how the class answered.
 *
 * Its own controller rather than three more actions on App\Controller\VideoResourceController,
 * which is already the whole of screens 1 and 2 - the split the repository asks for when a tool
 * gains a feature area.
 *
 * Nothing here writes a question: a marker points at a QuizQuestion that already exists in the
 * library, and the import goes through App\Service\QuizCsvImporter and its preview, which is what
 * keeps the promise made for the quiz import - nothing is written before the teacher confirms.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class VideoCuePointController extends AbstractController
{
    // One token for the editor's three actions (add, update, delete): they all fire from a single
    // screen, exactly as step 2's do.
    public const string CSRF_TOKEN_ID = 'video_cue_point';

    private const string SESSION_KEY = 'video_cue_import';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly VideoResourceRepository $resourceRepository,
        private readonly VideoCuePointRepository $cuePointRepository,
        private readonly VideoResourceAudienceResolver $audienceResolver,
    ) {
    }

    /**
     * Screen 3: the player, the timeline its markers are posted on, and the panel of the selected
     * one. The markers of one file, since a timeline belongs to a file and not to a set.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/questions', name: 'app_video_resource_cues', methods: ['GET'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function editor(int $resourceId, int $fileId, QuizTemplateRepository $templateRepository, TranslatorInterface $translator): Response
    {
        $resource = $this->findOwnResource($resourceId);
        $file = $this->findFileOrNotFound($resource, $fileId);

        return $this->render('video_resource/cues.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'file' => $file,
            'cuePoints' => array_map($this->cueJson(...), $this->cuePointRepository->findForFile($file)),
            // The teacher's own banks, to pick a statement from. The video's own bank is one of
            // them - an import from this screen wrote it.
            'templates' => array_map(
                static fn (QuizTemplate $template): array => ['id' => $template->getId(), 'name' => $template->getName()],
                $templateRepository->findForTeacher($this->currentUser()),
            ),
            'typeLabels' => $this->typeLabels($translator),
        ]);
    }

    /**
     * The questions of one bank, for the picker. Served on demand rather than laid into the page:
     * a teacher with twenty banks would otherwise carry every statement of every one of them.
     */
    // No `\d+` on templateId, like the file routes of VideoResourceController: the screen generates
    // this address as a template carrying a `__TEMPLATE_ID__` placeholder, and a numeric requirement
    // makes path() refuse to generate it at all. The id is cast and then looked up, so nothing rests
    // on the pattern.
    #[Route(path: '/tools/videos/{resourceId}/questions/library/{templateId}', name: 'app_video_resource_cue_library', methods: ['GET'], requirements: ['resourceId' => '\d+'])]
    public function library(int $resourceId, int $templateId, QuizTemplateRepository $templateRepository, TranslatorInterface $translator): JsonResponse
    {
        $this->findOwnResource($resourceId);
        $template = $templateRepository->find($templateId) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isStaff() && $template->getCreatedBy()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(['questions' => array_map(
            static fn (QuizQuestion $question): array => [
                'id' => $question->getId(),
                'label' => $question->getLabel(),
                'type' => $translator->trans($question->getType()->shortLabelKey()),
                'typeValue' => $question->getType()->value,
            ],
            $template->getQuestions()->toArray(),
        )]);
    }

    /**
     * Posting a marker, or changing the one selected. One action for both: the panel is the same
     * form either way, and the screen would otherwise have to know which of two addresses to use.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/questions/save', name: 'app_video_resource_cue_save', methods: ['POST'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function save(int $resourceId, int $fileId, Request $request, EntityManagerInterface $entityManager, QuizQuestionRepository $questionRepository): JsonResponse
    {
        $resource = $this->findOwnResource($resourceId);
        $file = $this->findFileOrNotFound($resource, $fileId);
        $this->assertCsrf($request);

        $payload = JsonRequestPayload::fromRequest($request);
        $timecode = $payload->int('timecode', 0) ?? 0;
        $questionId = $payload->int('questionId', 0) ?? 0;

        if (0 !== $file->getDurationSeconds() && $timecode > $file->getDurationSeconds()) {
            return $this->json(['error' => 'videoCueOutOfRangeError'], Response::HTTP_BAD_REQUEST);
        }

        $cueId = $payload->int('cueId', 0) ?? 0;
        $cuePoint = 0 === $cueId ? null : $this->findCueOrNotFound($file, $cueId);
        $question = 0 === $questionId ? $cuePoint?->getQuestion() : $questionRepository->find($questionId);

        if (null === $question) {
            return $this->json(['error' => 'videoCueQuestionRequiredError'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $cuePoint) {
            $cuePoint = new VideoCuePoint($file, $question, $timecode);
            $file->addCuePoint($cuePoint);
            $entityManager->persist($cuePoint);
        } else {
            $cuePoint->setQuestion($question)->setTimecodeSeconds($timecode);
        }

        $cuePoint->setPauseVideo($payload->bool('pauseVideo'))->setBlocking($payload->bool('blocking'));
        $entityManager->flush();

        return $this->json(['cuePoint' => $this->cueJson($cuePoint)]);
    }

    // Same placeholder rule as the library route above: no `\d+` on cueId.
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/questions/{cueId}/delete', name: 'app_video_resource_cue_delete', methods: ['POST'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function delete(int $resourceId, int $fileId, int $cueId, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $resource = $this->findOwnResource($resourceId);
        $file = $this->findFileOrNotFound($resource, $fileId);
        $this->assertCsrf($request);

        // The question itself stays in the library: a marker is a placement, not a copy, and
        // removing it from a video must not cost the teacher the statement they wrote.
        $cuePoint = $this->findCueOrNotFound($file, $cueId);
        $file->removeCuePoint($cuePoint);
        $entityManager->remove($cuePoint);
        $entityManager->flush();

        return $this->json(['cueCount' => $file->getCuePoints()->count()]);
    }

    /**
     * Screen 5: one row per marker - how many answered it, and how many got it right.
     *
     * Read against the whole video rather than one file: it is the diagnostic screen, and it is
     * meant to be laid beside the retention map of screen 2, which covers the same set.
     */
    #[Route(path: '/tools/videos/{resourceId}/questions/results', name: 'app_video_resource_cue_results', methods: ['GET'], requirements: ['resourceId' => '\d+'])]
    public function results(int $resourceId, VideoCueAnswerRepository $answerRepository): Response
    {
        $resource = $this->findOwnResource($resourceId);
        $counts = $answerRepository->countByCuePointForResource($resource);

        $rows = [];
        foreach ($this->cuePointRepository->findForResource($resource) as $cuePoint) {
            $count = $counts[(int) $cuePoint->getId()] ?? ['answers' => 0, 'correct' => 0];

            $rows[] = [
                'cuePoint' => $cuePoint,
                'answers' => $count['answers'],
                'correct' => $count['correct'],
                // Nobody having reached the question yet is left at null rather than shown as 0 %:
                // a marker at 11:05 of a twelve-minute lecture reads as catastrophic on the day it
                // is posted, when in truth it has simply not been played.
                'percent' => 0 === $count['answers'] ? null : (int) round($count['correct'] / $count['answers'] * 100),
            ];
        }

        return $this->render('video_resource/cue_results.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'rows' => $rows,
            'studentCount' => $answerRepository->countStudentsForResource($resource),
            'audienceCount' => \count($this->audienceResolver->resolveAudience($resource)),
        ]);
    }

    /**
     * Screen 3 bis: the CSV import, and the prompt to hand a language model.
     *
     * The prompt is the reason this screen exists rather than a link to the library's import: a
     * model cannot watch the video, so it needs a timed outline - and this screen, unlike the
     * library's, knows the title and the running time and writes them in.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/questions/import', name: 'app_video_resource_cue_import', methods: ['GET', 'POST'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function import(int $resourceId, int $fileId, Request $request, QuizCsvImporter $importer, TranslatorInterface $translator): Response
    {
        $resource = $this->findOwnResource($resourceId);
        $file = $this->findFileOrNotFound($resource, $fileId);

        $form = $this->createForm(QuizImportType::class, null, ['source' => QuizImportType::SOURCE_CSV]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            // Coming back here means starting over, exactly as the library's import does.
            $request->getSession()->remove(self::SESSION_KEY);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $upload */
            $upload = $form->get('file')->getData();

            try {
                $payload = $importer->parse($upload, VideoImportContext::forFile($file));
                $payload['fileId'] = $file->getId();
                $request->getSession()->set(self::SESSION_KEY, $payload);

                return $this->redirectToRoute('app_video_resource_cue_import_preview', ['resourceId' => $resourceId, 'fileId' => $fileId]);
            } catch (QuizCsvImportException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('video_resource/cue_import.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'file' => $file,
            'form' => $form,
            'video' => VideoImportContext::forFile($file),
        ]);
    }

    /**
     * The verification screen, and the only place this feature writes questions. It is the library
     * import's guarantee, restated: the rows are read, shown, and written only once confirmed.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/questions/import/preview', name: 'app_video_resource_cue_import_preview', methods: ['GET', 'POST'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function importPreview(int $resourceId, int $fileId, Request $request, EntityManagerInterface $entityManager, QuizCsvImporter $importer, TranslatorInterface $translator): Response
    {
        $resource = $this->findOwnResource($resourceId);
        $file = $this->findFileOrNotFound($resource, $fileId);

        $payload = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_array($payload) || [] === ($payload['questions'] ?? []) || ($payload['fileId'] ?? null) !== $file->getId()) {
            $this->addFlash('warning', 'quizImportExpiredFlashMessage');

            return $this->redirectToRoute('app_video_resource_cue_import', ['resourceId' => $resourceId, 'fileId' => $fileId]);
        }

        /** @var list<array<string, mixed>> $questions */
        $questions = array_values($payload['questions']);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            // Only the rows still ticked on the verification screen. Absent altogether (a browser
            // sends no unchecked box) means the teacher unticked everything.
            $keep = array_map(intval(...), $request->request->all('rows'));
            $selected = array_values(array_filter($questions, static fn (array $question, int $index): bool => \in_array($index, $keep, true), \ARRAY_FILTER_USE_BOTH));

            if ([] !== $selected) {
                $template = $this->questionBankOf($resource, $entityManager);
                $before = $template->getQuestions()->count();
                $importer->appendQuestions($template, $selected);

                // appendQuestions() appends in order, so the questions just created are the tail of
                // the bank - which is what pairs each of them back with the row it came from.
                $created = \array_slice($template->getQuestions()->toArray(), $before);
                foreach ($created as $index => $question) {
                    $entityManager->persist($question);
                    $timecode = $selected[$index]['timecode'] ?? null;
                    $cuePoint = new VideoCuePoint($file, $question, \is_int($timecode) ? $timecode : 0);
                    $file->addCuePoint($cuePoint);
                    $entityManager->persist($cuePoint);
                }

                $entityManager->flush();
            }

            $request->getSession()->remove(self::SESSION_KEY);
            $this->addFlash('success', 'videoCueImportCreatedFlashMessage');

            return $this->redirectToRoute('app_video_resource_cues', ['resourceId' => $resourceId, 'fileId' => $fileId]);
        }

        return $this->render('video_resource/cue_import_preview.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'file' => $file,
            'payload' => $payload,
            'questions' => $questions,
            'typeLabels' => $this->typeLabels($translator),
        ]);
    }

    // ---- Builders -----------------------------------------------------------------------------

    /**
     * The bank an import from this video writes to, created the first time and reused after - so
     * two imports on the same video build one bank rather than two.
     */
    private function questionBankOf(VideoResource $resource, EntityManagerInterface $entityManager): QuizTemplate
    {
        $template = $resource->getQuestionTemplate();
        if (null !== $template) {
            return $template;
        }

        $template = new QuizTemplate($this->currentUser());
        $template->setName(mb_substr((string) $resource->getName(), 0, 255));
        $template->setCreatedBy($this->currentUser());
        $entityManager->persist($template);
        $resource->setQuestionTemplate($template);

        return $template;
    }

    /**
     * The short badge label of every question type, for the screens that name a type from a value
     * rather than from an entity - the marker rows the editor draws itself, and the import preview,
     * whose rows are payload arrays and not questions yet.
     *
     * @return array<string, string> enum value => translated short label
     */
    private function typeLabels(TranslatorInterface $translator): array
    {
        return array_combine(
            array_map(static fn (QuestionType $case): string => $case->value, QuestionType::cases()),
            array_map(static fn (QuestionType $case): string => $translator->trans($case->shortLabelKey()), QuestionType::cases()),
        );
    }

    /** @return array<string, mixed> */
    private function cueJson(VideoCuePoint $cuePoint): array
    {
        $question = $cuePoint->getQuestion();

        return [
            'id' => $cuePoint->getId(),
            'timecode' => $cuePoint->getTimecodeSeconds(),
            'formattedTimecode' => $cuePoint->getFormattedTimecode(),
            'pauseVideo' => $cuePoint->isPauseVideo(),
            'blocking' => $cuePoint->isBlocking(),
            'questionId' => $question?->getId(),
            'label' => $question?->getLabel(),
            'type' => $question?->getType()->value,
        ];
    }

    // ---- Access -------------------------------------------------------------------------------

    /** The video, and the right to handle it: its author, or staff - the same rule as screens 1/2. */
    private function findOwnResource(int $resourceId): VideoResource
    {
        $resource = $this->resourceRepository->find($resourceId) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isStaff() && $resource->getCreatedBy()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $resource;
    }

    private function findFileOrNotFound(VideoResource $resource, int $fileId): VideoResourceFile
    {
        foreach ($resource->getFiles() as $file) {
            if ($file->getId() === $fileId) {
                return $file;
            }
        }

        throw $this->createNotFoundException();
    }

    private function findCueOrNotFound(VideoResourceFile $file, int $cueId): VideoCuePoint
    {
        foreach ($file->getCuePoints() as $cuePoint) {
            if ($cuePoint->getId() === $cueId) {
                return $cuePoint;
            }
        }

        throw $this->createNotFoundException();
    }

    private function assertCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
