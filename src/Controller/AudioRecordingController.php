<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\AudioRecording;
use App\Entity\AudioRecordingFile;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AudioRecordingMode;
use App\Enum\Feature;
use App\Repository\AudioListenProgressRepository;
use App\Repository\AudioRecordingRepository;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Service\AudioRecordingAudienceResolver;
use App\Service\AudioUploadService;
use App\Service\PostValue;
use App\Service\QueryValue;
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

/**
 * The teacher tool "Enregistrements audio" (design/design_handoff_enregistrements_audio): handing
 * audio files to a class, common or individualised, turning them into a Listening assignment, and
 * following who really listened.
 *
 * It replaces the gradebook's audio comments, whose recorder, format and storage chain it inherits
 * (App\Service\AudioUploadService) - the handoff's constraint.
 *
 * Two ways in, one single set of screens: the top bar's "Outils" menu, which shows every class and
 * asks for one at creation, and a class's own "Outils" submenu, which filters the list and
 * preselects the class. The context only lives in the list and creation routes: from step 2 on, the
 * recording carries its class and the breadcrumb names it either way (mockups 3 and 4), the two
 * paths merge, hence single routes.
 *
 * Teachers and staff only, like the rest of the classroom tools: this is teaching material, not a
 * student screen. Students listen from their assignment instead (App\Controller\StudentWorkController).
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Audio)]
class AudioRecordingController extends AbstractController
{
    // One token for all of step 2's actions (record, delete): they all fire from a single screen,
    // which therefore has a single token to carry.
    public const string CSRF_TOKEN_ID = 'audio_recording';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly AudioRecordingRepository $recordingRepository,
        private readonly AudioRecordingAudienceResolver $audienceResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The multi-class list, reached from the top bar's "Outils" menu: every class taught, with the
     * "Toutes les classes" filter at the top of the page.
     */
    #[Route(path: '/tools/audio-recordings', name: 'app_audio_recordings', methods: ['GET'])]
    public function list(Request $request, ProgramRepository $programRepository): Response
    {
        $programs = $this->teachingPrograms($programRepository);
        $filterId = QueryValue::int($request, 'class');

        return $this->renderList($programs, $filterId, null);
    }

    /**
     * The same list, reached from a class's "Outils" submenu: the class is known, the filter goes
     * away, and "+ Nouvel enregistrement" opens step 1 on it.
     */
    #[Route(path: '/programs/{id}/tools/audio-recordings', name: 'app_program_tools_audio_recordings', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listForProgram(int $id, ProgramRepository $programRepository): Response
    {
        $program = $this->findTeachableProgram($id, $programRepository);

        return $this->renderList([$program], (int) $program->getId(), $program);
    }

    /** Step 1 "Paramètres", with no class known: it is picked in the form. */
    #[Route(path: '/tools/audio-recordings/new', name: 'app_audio_recording_new', methods: ['GET', 'POST'])]
    public function create(Request $request, ProgramRepository $programRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->runSettings($request, $this->teachingPrograms($programRepository), null, $entityManager);
    }

    /** Step 1 "Paramètres", class preselected: that is the only difference between the two. */
    #[Route(path: '/programs/{id}/tools/audio-recordings/new', name: 'app_program_tools_audio_recording_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function createForProgram(int $id, Request $request, ProgramRepository $programRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findTeachableProgram($id, $programRepository);

        return $this->runSettings($request, $this->teachingPrograms($programRepository), $program, $entityManager);
    }

    /**
     * Step 2 "Fichiers audio". This is where a recording gets filled, and where "Compléter" brings a
     * draft back to.
     *
     * Unlike step 1, everything is saved as it happens: an audio file cannot travel inside a form up
     * to a "save" button, it goes from the mic to the app, and starting over on a page reload would
     * mean losing minutes of speech. "Enregistrer le brouillon" therefore only closes the screen.
     */
    #[Route(path: '/tools/audio-recordings/{recordingId}/files', name: 'app_audio_recording_files', methods: ['GET'], requirements: ['recordingId' => '\d+'])]
    public function files(int $recordingId, AudioUploadService $uploadService): Response
    {
        $recording = $this->findOwnRecording($recordingId);
        $audience = $this->audienceResolver->resolveAudience($recording);
        $optionsByStudentId = $this->audienceResolver->optionsByStudentId($recording);

        return $this->render('audio_recording/files.html.twig', [
            'recording' => $recording,
            'program' => $recording->getProgram(),
            'commonFiles' => array_map(fn (AudioRecordingFile $file): array => $this->fileJson($file, $uploadService), $recording->getCommonFiles()),
            'students' => array_map(fn (User $student): array => [
                'id' => $student->getId(),
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                'options' => array_map(
                    static fn (Option $option): array => ['shortName' => $option->getShortName(), 'color' => $option->getColor()],
                    $optionsByStudentId[$student->getId()] ?? [],
                ),
                'files' => array_map(fn (AudioRecordingFile $file): array => $this->fileJson($file, $uploadService), $recording->getIndividualFilesFor($student)),
            ], $audience),
            'coveredCount' => $recording->countStudentsWithFile($audience),
            'audienceCount' => \count($audience),
        ]);
    }

    /**
     * The recorded Blob, posted by the browser. The row is only written once the object is really in
     * the bucket: a failed transfer leaves no file pointing at nothing.
     *
     * `student` absent = common file; present = that student's file, and they must belong to the
     * audience - an individual file for someone who is not targeted would never be heard.
     */
    #[Route(path: '/tools/audio-recordings/{recordingId}/files/upload', name: 'app_audio_recording_upload', methods: ['POST'], requirements: ['recordingId' => '\d+'])]
    public function upload(int $recordingId, Request $request, EntityManagerInterface $entityManager, AudioUploadService $uploadService): JsonResponse
    {
        $recording = $this->findOwnRecording($recordingId);
        $this->assertCsrf($request);

        $student = null;
        $studentId = PostValue::int($request, 'student');
        if (0 !== $studentId) {
            $student = $this->findInAudienceOrNotFound($recording, $studentId);

            if (!$recording->isIndividual()) {
                throw $this->createNotFoundException();
            }
        }

        $file = $request->files->get('audio');
        $key = $uploadService->keyForRecording((int) $recording->getId(), $student?->getId());

        if (!$file instanceof UploadedFile || !$uploadService->store($key, $file)) {
            return $this->json(['error' => 'invalid-recording'], Response::HTTP_BAD_REQUEST);
        }

        $recordingFile = new AudioRecordingFile($recording, $key, $this->currentUser(), $student);
        $recordingFile
            ->setDurationSeconds(PostValue::int($request, 'duration'))
            ->setFileSize((int) $file->getSize())
            ->setOriginalName($this->fileNameFor($recording, $student));

        $entityManager->persist($recordingFile);
        $recording->setLastUpdatedBy($this->currentUser());
        $recording->setLastUpdatedDate(new \DateTimeImmutable());
        $entityManager->flush();

        $audience = $this->audienceResolver->resolveAudience($recording);

        return $this->json([
            'file' => $this->fileJson($recordingFile, $uploadService),
            'coveredCount' => $recording->countStudentsWithFile($audience),
        ]);
    }

    /**
     * Removing a file. The object leaves the bucket with its row: keeping it would only mean paying
     * for storage on a recording nothing points at any more.
     */
    // No `\d+` on fileId, unlike every other route here: the screen generates this one as a template
    // carrying a `__FILE_ID__` placeholder (the recording's rows are painted client-side), and a
    // numeric requirement makes path() refuse to generate it at all. The id is cast to int and then
    // looked up among the recording's own files, so nothing rests on the pattern anyway.
    #[Route(path: '/tools/audio-recordings/{recordingId}/files/{fileId}/delete', name: 'app_audio_recording_file_delete', methods: ['POST'], requirements: ['recordingId' => '\d+'])]
    public function deleteFile(int $recordingId, int $fileId, Request $request, EntityManagerInterface $entityManager, AudioUploadService $uploadService): JsonResponse
    {
        $recording = $this->findOwnRecording($recordingId);
        $this->assertCsrf($request);

        $file = $this->findFileOrNotFound($recording, $fileId);
        $uploadService->delete($file->getStorageKey());
        $entityManager->remove($file);
        $recording->removeFile($file);
        $entityManager->flush();

        $audience = $this->audienceResolver->resolveAudience($recording);

        return $this->json(['coveredCount' => $recording->countStudentsWithFile($audience)]);
    }

    /**
     * The playback URL, served on demand rather than laid into the page: an individualised recording
     * would put as many addresses there as there are students, of which the teacher will play a
     * handful.
     */
    #[Route(path: '/tools/audio-recordings/{recordingId}/files/{fileId}/playback-url', name: 'app_audio_recording_file_playback_url', methods: ['GET'], requirements: ['recordingId' => '\d+', 'fileId' => '\d+'])]
    public function playbackUrl(int $recordingId, int $fileId, AudioUploadService $uploadService): JsonResponse
    {
        $recording = $this->findOwnRecording($recordingId);

        return $this->json(['url' => $uploadService->playbackUrl($this->findFileOrNotFound($recording, $fileId)->getStorageKey())]);
    }

    /**
     * Screen 4 "Statistiques d'écoute": the summary, the average listening per file, then the detail
     * per student - one column per file that concerns them, common ones plus their own.
     *
     * Everything is computed here rather than stored: listening is a reading of audio_listen_progress
     * against the files of the moment, and adding or removing a file changes the figures with nothing
     * to recompute.
     */
    #[Route(path: '/tools/audio-recordings/{recordingId}/statistics', name: 'app_audio_recording_statistics', methods: ['GET'], requirements: ['recordingId' => '\d+'])]
    public function statistics(int $recordingId, AudioListenProgressRepository $progressRepository): Response
    {
        $recording = $this->findOwnRecording($recordingId);
        $audience = $this->audienceResolver->resolveAudience($recording);
        $optionsByStudentId = $this->audienceResolver->optionsByStudentId($recording);
        $progressByStudentId = $progressRepository->findByStudentAndFileForRecording($recording);

        // The table's columns: one common file = one column, which everyone shares. Individual files,
        // on the other hand, fit into ONE column: they belong to one student each, and giving them a
        // column apiece would make a table as wide as the class with empty cells everywhere else. The
        // handoff's "les colonnes fichiers d'un étudiant couvrent les fichiers communs + les siens"
        // still holds - that is how a row reads, not how wide the table is.
        $columns = array_map(static fn (AudioRecordingFile $file): array => [
            'key' => (string) $file->getId(),
            'label' => $file->getOriginalName(),
            'duration' => $file->getFormattedDuration(),
        ], $recording->getCommonFiles());

        if ($recording->isIndividual()) {
            $columns[] = ['key' => 'individual', 'label' => $this->translator->trans('audioRecordingIndividualColumnLabel'), 'duration' => null];
        }

        $rows = [];
        $percentsByColumn = [];
        $completionsByColumn = [];

        foreach ($audience as $student) {
            $cells = [];
            $percents = [];
            $dates = [];

            foreach ($recording->getFilesFor($student) as $file) {
                $progress = $progressByStudentId[$student->getId()][$file->getId()] ?? null;
                $percent = $progress?->getMaxListenedPercent() ?? 0;
                $percents[] = $percent;

                if (null !== $progress?->getLastListenedAt()) {
                    $dates[] = $progress->getLastListenedAt();
                }

                // Several individual files share the same cell: the lowest of their progresses, the
                // one still to be done for that column to be finished.
                $key = $file->isIndividual() ? 'individual' : (string) $file->getId();
                $cells[$key] = isset($cells[$key]) ? min($cells[$key], $percent) : $percent;
            }

            foreach ($cells as $key => $percent) {
                $percentsByColumn[$key][] = $percent;
                if (100 <= $percent) {
                    $completionsByColumn[$key] = ($completionsByColumn[$key] ?? 0) + 1;
                }
            }

            // A student's average is over THEIR files: in individualised mode two students do not have
            // the same number of them, and measuring everyone against the recording's total would make
            // the column lie.
            $rows[] = [
                'student' => $student,
                'options' => $optionsByStudentId[$student->getId()] ?? [],
                'cells' => $cells,
                'total' => [] === $percents ? 0 : (int) round(array_sum($percents) / \count($percents)),
                'lastListenedAt' => [] === $dates ? null : max($dates),
                'status' => $this->studentStatus($percents),
            ];
        }

        $columns = array_map(static function (array $column) use ($percentsByColumn, $completionsByColumn): array {
            $percents = $percentsByColumn[$column['key']] ?? [];

            return $column + [
                'average' => [] === $percents ? 0 : (int) round(array_sum($percents) / \count($percents)),
                'completed' => $completionsByColumn[$column['key']] ?? 0,
                'listeners' => \count($percents),
            ];
        }, $columns);

        $totals = array_column($rows, 'total');

        return $this->render('audio_recording/statistics.html.twig', [
            'recording' => $recording,
            'program' => $recording->getProgram(),
            'rows' => $rows,
            'columns' => $columns,
            'summary' => [
                'complete' => \count(array_filter($rows, static fn (array $row): bool => 'complete' === $row['status'])),
                'inProgress' => \count(array_filter($rows, static fn (array $row): bool => 'in_progress' === $row['status'])),
                'notStarted' => \count(array_filter($rows, static fn (array $row): bool => 'not_started' === $row['status'])),
                'average' => [] === $totals ? 0 : (int) round(array_sum($totals) / \count($totals)),
            ],
        ]);
    }

    // ---- Screens ------------------------------------------------------------------------------

    /** @param list<Program> $programs */
    private function renderList(array $programs, int $filterId, ?Program $scopedProgram): Response
    {
        $recordings = $this->recordingRepository->findForPrograms($programs, $this->accessChecker->isStaff() ? null : $this->currentUser());

        $rows = [];
        foreach ($recordings as $recording) {
            if (0 !== $filterId && $recording->getProgram()?->getId() !== $filterId) {
                continue;
            }

            $audience = $this->audienceResolver->resolveAudience($recording);
            $rows[] = [
                'recording' => $recording,
                'status' => $recording->getStatus($audience),
                'individualCount' => \count(array_filter(
                    $recording->getFiles()->toArray(),
                    static fn (AudioRecordingFile $file): bool => $file->isIndividual(),
                )),
                'commonCount' => \count($recording->getCommonFiles()),
            ];
        }

        return $this->render('audio_recording/list.html.twig', [
            'rows' => $rows,
            'programs' => $programs,
            'scopedProgram' => $scopedProgram,
            'filterId' => $filterId,
        ]);
    }

    /**
     * Step 1, in both its entry points. The form is written by hand rather than through a FormType:
     * it has four fields, two of which are chips and radio cards the mockup draws itself, and a
     * FormType would only make the template harder to read here.
     *
     * @param list<Program> $programs
     */
    private function runSettings(Request $request, array $programs, ?Program $preselected, EntityManagerInterface $entityManager): Response
    {
        // Coming from a class's submenu, that class is the subject of the screen even when it is
        // not one of one's own - staff opening step 1 on somebody else's class. It has to join the
        // list, which is both what the options chips are built from and what the POST resolves the
        // submitted class against; without it the form would refuse its own preselection.
        if (null !== $preselected && !\in_array($preselected, $programs, true)) {
            $programs[] = $preselected;
        }

        if ([] === $programs) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $submitted = [
            'name' => trim((string) $request->request->get('name', '')),
            'program' => PostValue::int($request, 'program', (int) $preselected?->getId()),
            'options' => array_map('intval', PostValue::all($request, 'options')),
        ];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $program = null;
            foreach ($programs as $candidate) {
                if ($candidate->getId() === $submitted['program']) {
                    $program = $candidate;
                }
            }

            // Not read from the request any more: every new recording is individualised
            // (design/validated/file-library.md, "Corrections audio"). The enum keeps its other case
            // for the recordings already created with it.
            $mode = AudioRecordingMode::Individual;

            if ('' === $submitted['name']) {
                $errors['name'] = 'audioRecordingNameRequiredMessage';
            }
            if (null === $program) {
                $errors['program'] = 'audioRecordingProgramRequiredMessage';
            }

            if ([] === $errors) {
                $recording = new AudioRecording($program);
                $recording->setName($submitted['name']);
                $recording->setMode($mode);
                $recording->setCreatedBy($this->currentUser());

                // An option ticked and then the class changed: the screen hides them, the server drops
                // them, exactly as the assignment wizard does with its recipients.
                foreach ($program->getOptions() as $option) {
                    if (\in_array($option->getId(), $submitted['options'], true)) {
                        $recording->addOption($option);
                    }
                }

                $entityManager->persist($recording);
                $entityManager->flush();

                return $this->redirectToRoute('app_audio_recording_files', ['recordingId' => $recording->getId()]);
            }
        }

        return $this->render('audio_recording/settings.html.twig', [
            'programs' => $programs,
            'scopedProgram' => $preselected,
            'submitted' => $submitted,
            'errors' => $errors,
        ]);
    }

    // ---- Builders -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function fileJson(AudioRecordingFile $file, AudioUploadService $uploadService): array
    {
        return [
            'id' => $file->getId(),
            'name' => $file->getOriginalName(),
            'duration' => $file->getFormattedDuration(),
            'playbackUrl' => $uploadService->playbackUrl($file->getStorageKey()),
        ];
    }

    /**
     * The name a file carries. The mockup writes "Consignes générales.mp3": that is placeholder data,
     * the format stays the recorder's - hence the real .webm extension rather than the mockup's.
     */
    private function fileNameFor(AudioRecording $recording, ?User $student): string
    {
        return sprintf(
            '%s - %s.webm',
            $recording->getName(),
            null === $student ? 'commun' : ($student->getDisplayName() ?? $student->getUsername()),
        );
    }

    /** @param list<int> $percents */
    private function studentStatus(array $percents): string
    {
        if ([] === $percents) {
            return 'not_started';
        }

        // "Terminé" only at 100% on EVERY file: the same rule as the assignment's completion, not a
        // more forgiving reading kept for the teacher.
        if ([] === array_filter($percents, static fn (int $percent): bool => $percent < 100)) {
            return 'complete';
        }

        return [] === array_filter($percents, static fn (int $percent): bool => $percent > 0) ? 'not_started' : 'in_progress';
    }

    // ---- Access -------------------------------------------------------------------------------

    /**
     * The classes a recording can be laid on: the ones actually TAUGHT, not the ones one has the
     * right to look at. Same rule as the tools reached through the class picker
     * (App\Controller\ToolsController) - a recording is somebody's own teaching material, so
     * offering a head of studies every class in the school would mostly be offering them classes
     * they will never record for.
     *
     * Whoever teaches nothing (administration, head of studies) keeps the full list, otherwise the
     * tool would have nothing to show them at all.
     *
     * @return list<Program>
     */
    private function teachingPrograms(ProgramRepository $repository): array
    {
        $viewer = $this->currentUser();
        $taught = $repository->findAllForTeacher($viewer);

        if ([] !== $taught) {
            return $taught;
        }

        return $this->accessChecker->isStaff()
            ? $repository->findActiveForNav($viewer)
            : [];
    }

    private function findTeachableProgram(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    /**
     * The recording, and the right to handle it: its author, or staff. A colleague teaching the same
     * class does not see it - it is somebody's teaching material, not a resource of the class.
     */
    private function findOwnRecording(int $recordingId): AudioRecording
    {
        $recording = $this->recordingRepository->find($recordingId) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isStaff() && $recording->getCreatedBy()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $recording;
    }

    private function findFileOrNotFound(AudioRecording $recording, int $fileId): AudioRecordingFile
    {
        foreach ($recording->getFiles() as $file) {
            if ($file->getId() === $fileId) {
                return $file;
            }
        }

        throw $this->createNotFoundException();
    }

    private function findInAudienceOrNotFound(AudioRecording $recording, int $studentId): User
    {
        foreach ($this->audienceResolver->resolveAudience($recording) as $student) {
            if ($student->getId() === $studentId) {
                return $student;
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
