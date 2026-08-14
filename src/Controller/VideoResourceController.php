<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PostValue;
use App\Service\QueryValue;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Repository\ProgramRepository;
use App\Repository\VideoResourceRepository;
use App\Repository\VideoWatchProgressRepository;
use App\Security\StructureAccessChecker;
use App\Service\VideoResourceAudienceResolver;
use App\Service\VideoRetention;
use App\Service\VideoUploadService;
use App\Service\VideoUploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The teacher tool "Vidéos" (design/comparaison/creas_video_5a_5b, screens 1 and 2): handing course
 * videos to a class, turning them into a Watching assignment, and following who really watched.
 *
 * Deliberately shaped after App\Controller\AudioRecordingController, screen for screen: the video
 * module is the audio module ported, and a teacher who knows one knows the other. Two ways in, one
 * set of screens - the top bar's "Outils" menu, which shows every class, and a class's own "Outils"
 * submenu, which filters the list and preselects the class.
 *
 * What is NOT here, and deliberately: no transcoding and no recorder. A video is uploaded, MP4 only
 * (App\Service\VideoUploadValidator), or it is not accepted at all - the app says so rather than
 * pretending to be a video platform.
 *
 * Teachers and staff only, like the rest of the classroom tools. Students watch from their
 * assignment instead (App\Controller\StudentWorkController).
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class VideoResourceController extends AbstractController
{
    // One token for all of step 2's actions (upload, delete): they all fire from a single screen,
    // which therefore has a single token to carry.
    public const string CSRF_TOKEN_ID = 'video_resource';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly VideoResourceRepository $resourceRepository,
        private readonly VideoResourceAudienceResolver $audienceResolver,
    ) {
    }

    /** The multi-class list, reached from the top bar's "Outils" menu. */
    #[Route(path: '/tools/videos', name: 'app_video_resources', methods: ['GET'])]
    public function list(Request $request, ProgramRepository $programRepository): Response
    {
        $programs = $this->teachingPrograms($programRepository);

        return $this->renderList($programs, QueryValue::int($request, 'class'), null);
    }

    /** The same list, reached from a class's "Outils" submenu: the class is known, the filter goes away. */
    #[Route(path: '/programs/{id}/tools/videos', name: 'app_program_tools_video_resources', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listForProgram(int $id, ProgramRepository $programRepository): Response
    {
        $program = $this->findTeachableProgram($id, $programRepository);

        return $this->renderList([$program], (int) $program->getId(), $program);
    }

    /** Step 1 "Paramètres", with no class known: it is picked in the form. */
    #[Route(path: '/tools/videos/new', name: 'app_video_resource_new', methods: ['GET', 'POST'])]
    public function create(Request $request, ProgramRepository $programRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->runSettings($request, $this->teachingPrograms($programRepository), null, $entityManager);
    }

    /** Step 1 "Paramètres", class preselected: that is the only difference between the two. */
    #[Route(path: '/programs/{id}/tools/videos/new', name: 'app_program_tools_video_resource_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function createForProgram(int $id, Request $request, ProgramRepository $programRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findTeachableProgram($id, $programRepository);

        return $this->runSettings($request, $this->teachingPrograms($programRepository), $program, $entityManager);
    }

    /**
     * Step 2 "Fichiers vidéo". This is where a video set gets filled, and where "Compléter" brings a
     * draft back to.
     *
     * Everything is saved as it happens, as in the audio tool: an upload of a hundred megabytes
     * cannot travel inside a form up to a "save" button, and starting over on a page reload would
     * mean uploading it twice. "Enregistrer le brouillon" therefore only closes the screen.
     */
    #[Route(path: '/tools/videos/{resourceId}/files', name: 'app_video_resource_files', methods: ['GET'], requirements: ['resourceId' => '\d+'])]
    public function files(int $resourceId): Response
    {
        $resource = $this->findOwnResource($resourceId);

        return $this->render('video_resource/files.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'files' => array_map($this->fileJson(...), $resource->getFiles()->toArray()),
            'audienceCount' => \count($this->audienceResolver->resolveAudience($resource)),
            'maxBytes' => VideoUploadValidator::MAX_BYTES,
        ]);
    }

    /**
     * The uploaded file. The row is only written once the object is really in the bucket: a failed
     * transfer leaves no file pointing at nothing.
     *
     * The duration comes from the browser, which has just read it off the file it is about to send:
     * measuring it server-side would mean shipping ffprobe for a number the player already knows,
     * and a wrong one only ever misdraws a progress bar - the tracking itself is in percent.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/upload', name: 'app_video_resource_upload', methods: ['POST'], requirements: ['resourceId' => '\d+'])]
    public function upload(
        int $resourceId,
        Request $request,
        EntityManagerInterface $entityManager,
        VideoUploadService $uploadService,
        VideoUploadValidator $validator,
    ): JsonResponse {
        $resource = $this->findOwnResource($resourceId);
        $this->assertCsrf($request);

        $file = $request->files->get('video');
        $file = $file instanceof UploadedFile ? $file : null;
        $error = $validator->validate($file);

        if (null !== $error || null === $file) {
            return $this->json(['error' => $error ?? 'videoUploadFailedError'], Response::HTTP_BAD_REQUEST);
        }

        $key = $uploadService->keyForResource((int) $resource->getId());

        if (!$uploadService->store($key, $file)) {
            return $this->json(['error' => 'videoUploadFailedError'], Response::HTTP_BAD_REQUEST);
        }

        $resourceFile = new VideoResourceFile($key, $resource->nextPosition());
        $resourceFile
            ->setDurationSeconds(PostValue::int($request, 'duration'))
            ->setFileSize((int) $file->getSize())
            ->setOriginalName($file->getClientOriginalName())
            ->setUploadedBy($this->currentUser());

        $resource->addFile($resourceFile);
        $entityManager->persist($resourceFile);
        $entityManager->flush();

        return $this->json(['file' => $this->fileJson($resourceFile)]);
    }

    /**
     * Removing a file. The object leaves the bucket with its row: a video is the heaviest thing this
     * app stores, and keeping one nothing points at is paying for nothing.
     */
    // No `\d+` on fileId, unlike every other route here: the screen generates this one as a template
    // carrying a `__FILE_ID__` placeholder, and a numeric requirement makes path() refuse to
    // generate it at all. The id is cast to int and then looked up among the resource's own files,
    // so nothing rests on the pattern anyway.
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/delete', name: 'app_video_resource_file_delete', methods: ['POST'], requirements: ['resourceId' => '\d+'])]
    public function deleteFile(int $resourceId, int $fileId, Request $request, EntityManagerInterface $entityManager, VideoUploadService $uploadService): JsonResponse
    {
        $resource = $this->findOwnResource($resourceId);
        $this->assertCsrf($request);

        $file = $this->findFileOrNotFound($resource, $fileId);
        $uploadService->delete($file->getStorageKey());
        $resource->removeFile($file);
        $entityManager->remove($file);
        $entityManager->flush();

        return $this->json(['fileCount' => $resource->getFiles()->count()]);
    }

    /**
     * The playback address, served on demand rather than laid into the page. It matters more here
     * than for audio: a video weighs ten to a hundred times an audio file, so an address in a page
     * that is never played is bandwidth given away.
     */
    #[Route(path: '/tools/videos/{resourceId}/files/{fileId}/playback-url', name: 'app_video_resource_file_playback_url', methods: ['GET'], requirements: ['resourceId' => '\d+', 'fileId' => '\d+'])]
    public function playbackUrl(int $resourceId, int $fileId, VideoUploadService $uploadService): JsonResponse
    {
        $resource = $this->findOwnResource($resourceId);

        return $this->json(['url' => $uploadService->playbackUrl($this->findFileOrNotFound($resource, $fileId)->getStorageKey())]);
    }

    /**
     * Screen 2 "Suivi de visionnage": the summary, the retention map minute by minute, then one row
     * per student.
     *
     * Everything is computed here rather than stored - watching is a reading of video_watch_progress
     * against the files of the moment - so adding or removing a file changes the figures with
     * nothing to recompute.
     */
    #[Route(path: '/tools/videos/{resourceId}/statistics', name: 'app_video_resource_statistics', methods: ['GET'], requirements: ['resourceId' => '\d+'])]
    public function statistics(int $resourceId, VideoWatchProgressRepository $progressRepository, VideoRetention $retention): Response
    {
        $resource = $this->findOwnResource($resourceId);
        $audience = $this->audienceResolver->resolveAudience($resource);
        $optionsByStudentId = $this->audienceResolver->optionsByStudentId($resource);
        $progressByStudentId = $progressRepository->findByStudentAndFileForResource($resource);
        $files = $resource->getFiles()->toArray();

        $rows = [];
        $percentsByFileId = [];

        foreach ($audience as $student) {
            $percents = [];
            $dates = [];

            foreach ($files as $file) {
                $progress = $progressByStudentId[(int) $student->getId()][(int) $file->getId()] ?? null;
                $percent = $progress?->getMaxWatchedPercent() ?? 0;
                $percents[(int) $file->getId()] = $percent;
                $percentsByFileId[(int) $file->getId()][] = $percent;

                if (null !== $progress?->getLastWatchedAt()) {
                    $dates[] = $progress->getLastWatchedAt();
                }
            }

            $rows[] = [
                'student' => $student,
                'options' => $optionsByStudentId[(int) $student->getId()] ?? [],
                'percents' => $percents,
                // Weighted by running time, as VideoWatchTracker weighs it: a twelve-minute lecture
                // and a thirty-second outro are not half the set each.
                'total' => $this->weightedPercent($files, $percents),
                'lastWatchedAt' => [] === $dates ? null : max($dates),
                'status' => $this->studentStatus(array_values($percents)),
            ];
        }

        $totals = array_column($rows, 'total');

        // The map is drawn for one file: a set of several has no single timeline, and the first is
        // the one the screen shows the curve of. The per-file bars below say where the rest stand.
        $mapped = $files[0] ?? null;

        return $this->render('video_resource/statistics.html.twig', [
            'resource' => $resource,
            'program' => $resource->getProgram(),
            'rows' => $rows,
            'files' => array_map(static fn (VideoResourceFile $file): array => [
                'file' => $file,
                'average' => [] === ($percentsByFileId[(int) $file->getId()] ?? [])
                    ? 0
                    : (int) round(array_sum($percentsByFileId[(int) $file->getId()]) / \count($percentsByFileId[(int) $file->getId()])),
            ], $files),
            'mappedFile' => $mapped,
            'curve' => null === $mapped
                ? $retention->curve([], 0)
                : $retention->curve($percentsByFileId[(int) $mapped->getId()] ?? [], $mapped->getDurationSeconds()),
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
        $resources = $this->resourceRepository->findForPrograms($programs, $this->accessChecker->isStaff() ? null : $this->currentUser());

        $rows = [];
        foreach ($resources as $resource) {
            if (0 !== $filterId && $resource->getProgram()?->getId() !== $filterId) {
                continue;
            }

            $rows[] = [
                'resource' => $resource,
                'status' => $resource->getStatus(),
                'fileCount' => $resource->getFiles()->count(),
            ];
        }

        return $this->render('video_resource/list.html.twig', [
            'rows' => $rows,
            'programs' => $programs,
            'scopedProgram' => $scopedProgram,
            'filterId' => $filterId,
        ]);
    }

    /**
     * Step 1, in both its entry points. Written by hand rather than through a FormType, as the audio
     * tool's is: three fields, one of which is the chips row the mockup draws itself.
     *
     * @param list<Program> $programs
     */
    private function runSettings(Request $request, array $programs, ?Program $preselected, EntityManagerInterface $entityManager): Response
    {
        // Coming from a class's submenu, that class is the subject of the screen even when it is not
        // one of one's own - staff opening step 1 on somebody else's class. It has to join the list,
        // which is both what the options chips are built from and what the POST resolves the
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
            'options' => array_map('intval', $request->request->all('options')),
        ];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $program = null;
            foreach ($programs as $candidate) {
                if ($candidate->getId() === $submitted['program']) {
                    $program = $candidate;
                }
            }

            if ('' === $submitted['name']) {
                $errors['name'] = 'videoResourceNameRequiredMessage';
            }
            if (null === $program) {
                $errors['program'] = 'videoResourceProgramRequiredMessage';
            }

            if ([] === $errors && null !== $program) {
                $resource = new VideoResource($program, $this->currentUser());
                $resource->setName($submitted['name']);

                // An option ticked and then the class changed: the screen hides them, the server
                // drops them, exactly as the assignment wizard does with its recipients.
                foreach ($program->getOptions() as $option) {
                    if (\in_array($option->getId(), $submitted['options'], true)) {
                        $resource->addOption($option);
                    }
                }

                $entityManager->persist($resource);
                $entityManager->flush();

                return $this->redirectToRoute('app_video_resource_files', ['resourceId' => $resource->getId()]);
            }
        }

        return $this->render('video_resource/settings.html.twig', [
            'programs' => $programs,
            'scopedProgram' => $preselected,
            'submitted' => $submitted,
            'errors' => $errors,
        ]);
    }

    // ---- Builders -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function fileJson(VideoResourceFile $file): array
    {
        return [
            'id' => $file->getId(),
            'name' => $file->getOriginalName(),
            'duration' => $file->getFormattedDuration(),
            'size' => $file->getFileSize(),
        ];
    }

    /**
     * @param list<VideoResourceFile> $files
     * @param array<int, int>         $percents
     */
    private function weightedPercent(array $files, array $percents): int
    {
        $total = 0;
        $watched = 0.0;

        foreach ($files as $file) {
            $total += $file->getDurationSeconds();
            $watched += $file->getDurationSeconds() * (($percents[(int) $file->getId()] ?? 0) / 100);
        }

        return 0 === $total ? 0 : (int) floor($watched / $total * 100);
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
     * The classes a video can be laid on: the ones actually TAUGHT, not the ones one has the right
     * to look at - same rule as the audio tool. Whoever teaches nothing (administration, head of
     * studies) keeps the full list, otherwise the tool would have nothing to show them at all.
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
     * The video, and the right to handle it: its author, or staff. A colleague teaching the same
     * class does not see it - it is somebody's teaching material, not a resource of the class.
     */
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
