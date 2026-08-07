<?php

namespace App\Controller;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoScanResult;
use App\Form\EcoCourseType;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoParcoursRepository;
use App\Repository\EcoPositionPingRepository;
use App\Repository\EcoRunnerRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\EcoCourseCodeGenerator;
use App\Service\EcoLiveTrackingService;
use App\Service\EcoPerformanceAnalyzer;
use App\Service\EcoRunnerStatsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// Courses of a Ready EcoParcours - see reference/e-CO.dc.html screen 1g. Every route here is
// nested under a parcours (or reached from an existing EcoCourse, whose parcours ownership is
// what's actually checked - see findCourseOrNotFound()), same voter as EcoParcoursController.
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class EcoCourseController extends AbstractController
{
    #[Route(path: '/eco/parcours/{parcoursId}/courses', name: 'app_eco_course_index')]
    public function index(int $parcoursId, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $parcoursRepository, EcoCourseRepository $courseRepository, EcoCourseCodeGenerator $codeGenerator): Response
    {
        $parcours = $this->findParcoursOrNotFound($parcoursRepository, $parcoursId);
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);
        if (!$parcours->isReady()) {
            throw $this->createNotFoundException();
        }

        $course = new EcoCourse($parcours, $this->currentUser());
        $form = $this->createForm(EcoCourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $course->setCode($codeGenerator->generate());
            $entityManager->persist($course);
            $entityManager->flush();

            $this->addFlash('success', 'ecoCourseCreatedFlashMessage');

            return $this->redirectToRoute('app_eco_course_index', ['parcoursId' => $parcours->getId()]);
        }

        return $this->render('eco/course_index.html.twig', [
            'parcours' => $parcours,
            'courses' => $courseRepository->findForParcours($parcours),
            'form' => $form,
        ]);
    }

    #[Route(path: '/eco/courses/{id}/edit', name: 'app_eco_course_edit')]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager, EcoCourseRepository $repository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        if (EcoCourseStatus::Prepared !== $course->getStatus()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(EcoCourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'ecoCourseUpdatedFlashMessage');

            return $this->redirectToRoute('app_eco_course_index', ['parcoursId' => $course->getParcours()->getId()]);
        }

        return $this->render('eco/course_edit.html.twig', [
            'course' => $course,
            'form' => $form,
        ]);
    }

    #[Route(path: '/eco/courses/{id}/start', name: 'app_eco_course_start', methods: ['POST'])]
    public function start(int $id, Request $request, EntityManagerInterface $entityManager, EcoCourseRepository $repository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);

        if (!$this->isCsrfTokenValid('eco_course_start', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (EcoCourseStatus::Prepared !== $course->getStatus()) {
            throw $this->createAccessDeniedException();
        }

        $course->setStatus(EcoCourseStatus::InProgress);
        $course->setStartedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'ecoCourseStartedFlashMessage');

        return $this->redirectToRoute('app_eco_course_index', ['parcoursId' => $course->getParcours()->getId()]);
    }

    #[Route(path: '/eco/courses/{id}/close', name: 'app_eco_course_close', methods: ['POST'])]
    public function close(int $id, Request $request, EntityManagerInterface $entityManager, EcoCourseRepository $repository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);

        if (!$this->isCsrfTokenValid('eco_course_close', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (EcoCourseStatus::InProgress !== $course->getStatus()) {
            throw $this->createAccessDeniedException();
        }

        $course->setStatus(EcoCourseStatus::Closed);
        $course->setClosedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'ecoCourseClosedFlashMessage');

        return $this->redirectToRoute('app_eco_course_index', ['parcoursId' => $course->getParcours()->getId()]);
    }

    // Copies a course's settings (not its runners/results) into a fresh Prepared course with a
    // newly generated code - screen 1g's "Dupliquer" on a Closed course, e.g. running the same
    // setup again with a different class.
    #[Route(path: '/eco/courses/{id}/duplicate', name: 'app_eco_course_duplicate', methods: ['POST'])]
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, EcoCourseRepository $repository, EcoCourseCodeGenerator $codeGenerator, TranslatorInterface $translator): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);

        if (!$this->isCsrfTokenValid('eco_course_duplicate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $copy = new EcoCourse($course->getParcours(), $this->currentUser());
        $copy->setName($translator->trans('ecoCourseDuplicateNameTemplate', ['%name%' => $course->getName()]));
        $copy->setMode($course->getMode());
        $copy->setTeamsEnabled($course->isTeamsEnabled());
        $copy->setMapVisibility($course->getMapVisibility());
        $copy->setSafetyAlertsEnabled($course->isSafetyAlertsEnabled());
        $copy->setCode($codeGenerator->generate());

        $entityManager->persist($copy);
        $entityManager->flush();

        $this->addFlash('success', 'ecoCourseDuplicatedFlashMessage');

        return $this->redirectToRoute('app_eco_course_index', ['parcoursId' => $course->getParcours()->getId()]);
    }

    #[Route(path: '/eco/courses/{id}/live', name: 'app_eco_course_live')]
    public function live(int $id, EcoCourseRepository $repository, EcoLiveTrackingService $liveTracking): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $liveTracking->sortedBySeverity($course->getRunners()->toArray());

        return $this->render('eco/course_live.html.twig', [
            'course' => $course,
            'rows' => array_map(fn (EcoRunner $runner): array => [...$liveTracking->runnerLiveRow($runner), 'runner' => $runner], $runners),
        ]);
    }

    // Polled every ~10s by assets/controllers/eco_live_controller.js (screen 1h's "rafraîchie
    // toutes les 10 s").
    #[Route(path: '/eco/courses/{id}/live/data', name: 'app_eco_course_live_data')]
    public function liveData(int $id, EcoCourseRepository $repository, EcoLiveTrackingService $liveTracking): JsonResponse
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $liveTracking->sortedBySeverity($course->getRunners()->toArray());

        return $this->json([
            'runners' => array_map(fn (EcoRunner $runner): array => $liveTracking->runnerLiveRow($runner), $runners),
        ]);
    }

    #[Route(path: '/eco/courses/{courseId}/runners/{runnerId}/sos-clear', name: 'app_eco_course_sos_clear', methods: ['POST'])]
    public function sosClear(int $courseId, int $runnerId, Request $request, EntityManagerInterface $entityManager, EcoCourseRepository $repository, EcoRunnerRepository $runnerRepository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $courseId);

        if (!$this->isCsrfTokenValid('eco_course_sos_clear', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $runner = $runnerRepository->find($runnerId) ?? throw $this->createNotFoundException();
        if ($runner->getCourse() !== $course) {
            throw $this->createNotFoundException();
        }

        $runner->clearSos();
        $entityManager->flush();

        return $this->redirectToRoute('app_eco_course_live', ['id' => $course->getId()]);
    }

    #[Route(path: '/eco/courses/{id}/results', name: 'app_eco_course_results')]
    public function results(
        int $id,
        Request $request,
        EcoCourseRepository $repository,
        EcoRunnerStatsCalculator $statsCalculator,
        EcoPerformanceAnalyzer $analyzer,
        EcoPositionPingRepository $pingRepository,
        TranslatorInterface $translator,
    ): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $this->sortedByPseudo($course->getRunners()->toArray());

        $selectedRunner = null;
        $selectedId = $this->queryId($request, 'runner');
        foreach ($runners as $runner) {
            if ($runner->getId() === $selectedId) {
                $selectedRunner = $runner;

                break;
            }
        }
        $selectedRunner ??= $runners[0] ?? null;

        $stats = null !== $selectedRunner ? $statsCalculator->calculate($selectedRunner) : null;
        $analysis = null !== $selectedRunner ? $analyzer->analyse($selectedRunner, $runners) : null;

        // "Comparer à" - a second runner's trace laid over the first, for the route debate. Never
        // the runner already shown.
        $comparedRunner = null;
        $comparedId = $this->queryId($request, 'compare');
        foreach ($runners as $runner) {
            if ($runner->getId() === $comparedId && $runner !== $selectedRunner) {
                $comparedRunner = $runner;

                break;
            }
        }

        // Les tronçons du coureur comparé, pour que chaque infobulle porte les deux lectures.
        $comparedLegs = null !== $comparedRunner ? $analyzer->analyse($comparedRunner, $runners)['legs'] : [];

        return $this->render('eco/course_results.html.twig', [
            'course' => $course,
            'runners' => $runners,
            'selectedRunner' => $selectedRunner,
            'comparedRunner' => $comparedRunner,
            'stats' => $stats,
            'analysis' => $analysis,
            'map' => null !== $selectedRunner && null !== $stats
                ? $this->resultMap($selectedRunner, $stats['pings'], $analysis['stops'], $analysis['legs'], $comparedRunner, $comparedLegs, $pingRepository, $translator)
                : null,
        ]);
    }

    /**
     * A numeric id read from the query string, or 0 for anything that is not one. Both selects on
     * this screen can post an empty value - "Personne" clears the comparison - and getInt() throws
     * a 400 on an empty string rather than falling back to its default.
     */
    private function queryId(Request $request, string $key): int
    {
        $raw = $request->query->get($key);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * What the results map draws: the runner's own GPS trace, and every checkpoint of the parcours
     * with whether that runner validated it. Null when there is nothing to draw - a runner who
     * never sent a position, or a parcours whose checkpoints were never located.
     *
     * @param list<EcoPositionPing>      $pings
     * @param list<array<string, mixed>> $stops
     * @param list<array<string, mixed>> $legs
     *
     * @return array<string, mixed>|null
     */
    private function resultMap(
        EcoRunner $runner,
        array $pings,
        array $stops,
        array $legs,
        ?EcoRunner $comparedRunner,
        array $comparedLegs,
        EcoPositionPingRepository $pingRepository,
        TranslatorInterface $translator,
    ): ?array {
        $validatedTimes = [];
        $validatingScans = [];
        $attemptCounts = [];
        foreach ($runner->getScans() as $scan) {
            $checkpointId = (int) $scan->getCheckpoint()->getId();
            $attemptCounts[$checkpointId] = ($attemptCounts[$checkpointId] ?? 0) + 1;

            if (EcoScanResult::Success === $scan->getResult() && !isset($validatedTimes[$checkpointId])) {
                $validatedTimes[$checkpointId] = $scan->getScannedAt()?->format('H:i:s');
                $validatingScans[$checkpointId] = $scan;
            }
        }

        // A leg's search time belongs to the checkpoint it was spent hunting for.
        $searchSeconds = [];
        foreach ($legs as $leg) {
            if (null !== ($leg['searchSeconds'] ?? null)) {
                $searchSeconds[$leg['toCheckpointId']] = $leg['searchSeconds'];
            }
        }

        $checkpoints = $runner->getCourse()->getParcours()->getCheckpoints()->toArray();
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        $drawn = [];
        foreach ($checkpoints as $checkpoint) {
            if (!$checkpoint->isLocated()) {
                continue;
            }

            $checkpointId = (int) $checkpoint->getId();
            $validated = \array_key_exists($checkpointId, $validatedTimes);
            $name = $checkpoint->getName() ?? '';
            $latitude = (float) $checkpoint->getLatitude();
            $longitude = (float) $checkpoint->getLongitude();

            // A loop has its Départ and its Arrivée on the same spot, where one marker would sit
            // on top of the other and hide it: they become a single one, D/A, whose tooltip keeps
            // both passages.
            $lines = $this->checkpointLines(
                $name,
                $validated ? $validatingScans[$checkpointId] : null,
                $attemptCounts[$checkpointId] ?? 0,
                $searchSeconds[$checkpointId] ?? null,
                $translator,
            );

            $sharedIndex = $this->coLocatedIndex($drawn, $latitude, $longitude);
            if (null !== $sharedIndex) {
                $drawn[$sharedIndex]['label'] .= '/'.$this->checkpointLabel($checkpoint);
                $drawn[$sharedIndex]['lines'] = [...$drawn[$sharedIndex]['lines'], '', ...$lines];
                $drawn[$sharedIndex]['validated'] = $drawn[$sharedIndex]['validated'] && $validated;

                continue;
            }

            $drawn[] = [
                'label' => $this->checkpointLabel($checkpoint),
                'lines' => $lines,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'validated' => $validated,
            ];
        }

        $trace = $this->traceOf($pings);

        if ([] === $drawn && [] === $trace) {
            return null;
        }

        return [
            'trace' => $trace,
            'checkpoints' => $drawn,
            'stops' => array_map(
                fn (array $stop): array => $stop + ['lines' => [
                    $translator->trans('ecoResultsMapStopTitleLabel'),
                    $stop['at'],
                    $translator->trans('ecoResultsMapStopDurationLabel', ['%seconds%' => $stop['seconds']]),
                ]],
                $stops,
            ),
            // Only when the map has one runner on it: adding two coloured legs on top of a second
            // runner's trace would make four overlapping lines out of a comparison.
            // Every leg is drawn on its own so each can be hovered; the extremes are the only two
            // that take a colour, and only when no second runner shares the map (four coloured
            // lines on one map stop being readable).
            'legs' => $this->mapLegs($legs, $translator, $runner, $comparedRunner, $comparedLegs),
            // Where a refused scan was actually made, and which checkpoint it claimed: a dashed
            // line between the two is what makes "1 390 m" readable at a glance.
            'refusedScans' => $this->refusedScans($runner),
            'compared' => null !== $comparedRunner
                ? [
                    'pseudo' => $comparedRunner->getPseudo() ?? '',
                    'trace' => $this->traceOf($pingRepository->findForRunner($comparedRunner)),
                ]
                : null,
        ];
    }

    /**
     * Every leg the runner ran, as its own line on the map: the points to draw, what the hover
     * says, and which of the two extremes it is.
     *
     * The colour is only ever put on the leg that strayed furthest from the straight line and on
     * the one run straightest, only when at least two legs have a ratio to compare - crowning a
     * single leg both best and worst would say nothing - and only while the map carries one
     * runner: four coloured lines over two traces stop being readable.
     *
     * @param list<array<string, mixed>> $legs
     * @param list<array<string, mixed>> $comparedLegs
     *
     * @return list<array{points: list<array{float, float}>, kind: ?string, lines: list<string>}>
     */
    private function mapLegs(
        array $legs,
        TranslatorInterface $translator,
        EcoRunner $runner,
        ?EcoRunner $comparedRunner,
        array $comparedLegs,
    ): array {
        $drawable = array_values(array_filter($legs, static fn (array $leg): bool => \count($leg['points']) > 1));
        $measured = array_values(array_filter($drawable, static fn (array $leg): bool => null !== $leg['detourRatio']));

        $bestKey = null;
        $worstKey = null;
        if (null === $comparedRunner && \count($measured) >= 2) {
            usort($measured, static fn (array $a, array $b): int => $a['detourRatio'] <=> $b['detourRatio']);
            $bestKey = $this->legKey($measured[0]);
            $worstKey = $this->legKey($measured[\count($measured) - 1]);
        }

        // The compared runner's own reading of the same two checkpoints, whichever way round they
        // ran them (EcoPerformanceAnalyzer keys legs on the pair, without direction).
        $comparedByPair = [];
        foreach ($comparedLegs as $leg) {
            $comparedByPair[$leg['pairKey']] ??= $leg;
        }

        return array_map(function (array $leg) use ($translator, $bestKey, $worstKey, $runner, $comparedRunner, $comparedByPair): array {
            $key = $this->legKey($leg);
            $kind = match (true) {
                null !== $worstKey && $key === $worstKey => 'worst',
                null !== $bestKey && $key === $bestKey => 'best',
                default => null,
            };

            return [
                'points' => $leg['points'],
                'kind' => $kind,
                'lines' => null === $comparedRunner
                    ? $this->legLines($leg, $kind, $translator)
                    : $this->comparedLegLines($leg, $runner, $comparedRunner, $comparedByPair[$leg['pairKey']] ?? null, $translator),
            ];
        }, $drawable);
    }

    /**
     * The same leg read for both runners, one line each: who, then their time, distance and
     * detour. A leg the other runner never covered says so rather than showing a blank.
     *
     * @param array<string, mixed>  $leg
     * @param ?array<string, mixed> $comparedLeg
     *
     * @return list<string>
     */
    private function comparedLegLines(
        array $leg,
        EcoRunner $runner,
        EcoRunner $comparedRunner,
        ?array $comparedLeg,
        TranslatorInterface $translator,
    ): array {
        return [
            \sprintf('%s → %s', $leg['fromName'], $leg['toName']),
            \sprintf('%s — %s', $runner->getPseudo() ?? '', $this->legFigures($leg)),
            \sprintf('%s — %s', $comparedRunner->getPseudo() ?? '', null !== $comparedLeg
                ? $this->legFigures($comparedLeg)
                : $translator->trans('ecoResultsMapLegNotRunLabel')),
        ];
    }

    /** "4:20 · 344 m · ×1,20" - compact enough to sit on one line per runner. */
    private function legFigures(array $leg): string
    {
        $figures = [
            \sprintf('%d:%02d', intdiv($leg['seconds'], 60), $leg['seconds'] % 60),
            \sprintf('%d m', round($leg['travelledMeters'])),
        ];

        if (null !== $leg['detourRatio']) {
            $figures[] = '×'.number_format($leg['detourRatio'], 2, ',', ' ');
        }

        return implode(' · ', $figures);
    }

    /** @param array<string, mixed> $leg */
    private function legKey(array $leg): string
    {
        return $leg['pairKey'].'@'.$leg['seconds'];
    }

    /**
     * What a leg says on hover: which leg, how long it took, how far the runner actually ran, and
     * how straight that was - the same four figures as its row in the table below the map.
     *
     * @param array<string, mixed> $leg
     *
     * @return list<string>
     */
    private function legLines(array $leg, ?string $kind, TranslatorInterface $translator): array
    {
        $lines = [];

        if (null !== $kind) {
            $lines[] = $translator->trans('worst' === $kind ? 'ecoResultsMapWorstDetourLabel' : 'ecoResultsMapBestDetourLabel');
        }

        $lines[] = \sprintf('%s → %s', $leg['fromName'], $leg['toName']);
        $lines[] = $translator->trans('ecoResultsMapLegTimeLabel', [
            '%time%' => \sprintf('%d:%02d', intdiv($leg['seconds'], 60), $leg['seconds'] % 60),
        ]);
        $lines[] = $translator->trans('ecoResultsMapLegDistanceLabel', ['%meters%' => round($leg['travelledMeters'])]);

        if (null !== $leg['detourRatio']) {
            $lines[] = $translator->trans('ecoResultsMapDetourRatioLabel', [
                '%ratio%' => number_format($leg['detourRatio'], 2, ',', ' '),
            ]);
        }

        return $lines;
    }

    /**
     * @param list<EcoPositionPing> $pings
     *
     * @return list<array{float, float}>
     */
    private function traceOf(array $pings): array
    {
        return array_map(
            static fn (EcoPositionPing $ping): array => [$ping->getLatitude(), $ping->getLongitude()],
            $pings,
        );
    }

    /**
     * Scans this runner made that did not count, and that carry a position of their own - the
     * ones refused for distance are exactly the ones worth seeing on a map.
     *
     * @return list<array{latitude: float, longitude: float, checkpointLatitude: ?float, checkpointLongitude: ?float, tooltip: string}>
     */
    private function refusedScans(EcoRunner $runner): array
    {
        $refused = [];

        foreach ($runner->getScans() as $scan) {
            if (EcoScanResult::Success === $scan->getResult() || null === $scan->getLatitude() || null === $scan->getLongitude()) {
                continue;
            }

            $checkpoint = $scan->getCheckpoint();
            $refused[] = [
                'latitude' => (float) $scan->getLatitude(),
                'longitude' => (float) $scan->getLongitude(),
                'checkpointLatitude' => $checkpoint->isLocated() ? (float) $checkpoint->getLatitude() : null,
                'checkpointLongitude' => $checkpoint->isLocated() ? (float) $checkpoint->getLongitude() : null,
                'tooltip' => \sprintf(
                    '%s · %s%s',
                    $checkpoint->getName() ?? '',
                    $scan->getScannedAt()?->format('H:i:s') ?? '',
                    null !== $scan->getDistanceMeters() ? ' · '.round($scan->getDistanceMeters()).' m' : '',
                ),
            ];
        }

        return $refused;
    }

    /** Départ and Arrivée are marked D and A; a regular checkpoint carries its own number. */
    private function checkpointLabel(EcoCheckpoint $checkpoint): string
    {
        return match ($checkpoint->getType()) {
            EcoCheckpointType::Start => 'D',
            EcoCheckpointType::Finish => 'A',
            EcoCheckpointType::Checkpoint => (string) $checkpoint->getPosition(),
        };
    }

    /**
     * What the marker says on hover: the checkpoint, whether it was validated and when, how far
     * off the scan was made, how long the flag took to find, and how many attempts it took. Each
     * line is plain text - the map builds the tooltip out of text nodes, so a checkpoint named
     * with a "<" is never a way to inject markup.
     *
     * @return list<string>
     */
    private function checkpointLines(
        string $name,
        ?EcoCheckpointScan $validatingScan,
        int $attempts,
        ?int $searchSeconds,
        TranslatorInterface $translator,
    ): array {
        $lines = [$name];

        if (null === $validatingScan) {
            $lines[] = $translator->trans('ecoResultsMapCheckpointNotValidatedLabel');

            return $lines;
        }

        $lines[] = $translator->trans('ecoResultsMapCheckpointValidatedLabel', [
            '%time%' => $validatingScan->getScannedAt()?->format('H:i:s') ?? '',
        ]);

        if (null !== $validatingScan->getDistanceMeters()) {
            $lines[] = $translator->trans('ecoResultsMapCheckpointGapLabel', [
                '%meters%' => round($validatingScan->getDistanceMeters()),
            ]);
        }

        // A zero-second search is a runner who scanned on arrival: a line saying so would be noise.
        if (null !== $searchSeconds && $searchSeconds > 0) {
            $lines[] = $translator->trans('ecoResultsMapCheckpointSearchLabel', ['%seconds%' => $searchSeconds]);
        }

        if ($attempts > 1) {
            $lines[] = $translator->trans('ecoResultsMapCheckpointAttemptsLabel', ['%count%' => $attempts]);
        }

        return $lines;
    }

    /**
     * Index of an already-drawn marker standing on the same spot, if any - "same" meaning within
     * the radius below, since a Départ and an Arrivée posted at one place are rarely typed in with
     * the very same decimals.
     *
     * @param list<array{label: string, tooltip: string, latitude: float, longitude: float, validated: bool}> $drawn
     */
    private function coLocatedIndex(array $drawn, float $latitude, float $longitude): ?int
    {
        // ~0.0002° is about 20 m, the tolerance a checkpoint is validated within anyway.
        $threshold = 0.0002;

        foreach ($drawn as $index => $marker) {
            if (abs($marker['latitude'] - $latitude) < $threshold && abs($marker['longitude'] - $longitude) < $threshold) {
                return $index;
            }
        }

        return null;
    }

    #[Route(path: '/eco/courses/{courseId}/runners/{runnerId}/results/csv', name: 'app_eco_course_results_csv')]
    public function resultsCsv(int $courseId, int $runnerId, EcoCourseRepository $repository, EcoRunnerRepository $runnerRepository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $courseId);
        $runner = $runnerRepository->find($runnerId) ?? throw $this->createNotFoundException();
        if ($runner->getCourse() !== $course) {
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(function () use ($runner): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Balise', 'Heure', 'Ecart (m)', 'Methode', 'Resultat']);
            foreach ($runner->getScans() as $scan) {
                fputcsv($handle, [
                    $scan->getCheckpoint()->getName(),
                    $scan->getScannedAt()?->format('H:i:s'),
                    null !== $scan->getDistanceMeters() ? round($scan->getDistanceMeters()) : '',
                    $scan->getMethod()->value,
                    $scan->getResult()->value,
                ]);
            }
            fclose($handle);
        });
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('eco-resultats-%s.csv', $runner->getPseudo())));

        return $response;
    }

    /** @param list<EcoRunner> $runners
     * @return list<EcoRunner> */
    private function sortedByPseudo(array $runners): array
    {
        usort($runners, static fn (EcoRunner $a, EcoRunner $b): int => ($a->getPseudo() ?? '') <=> ($b->getPseudo() ?? ''));

        return $runners;
    }

    private function findParcoursOrNotFound(EcoParcoursRepository $repository, int $id): EcoParcours
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function findCourseOrNotFound(EcoCourseRepository $repository, int $id): EcoCourse
    {
        $course = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $course->getParcours());

        return $course;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
