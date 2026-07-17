<?php

namespace App\Controller;

use App\Entity\EcoCheckpointScan;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\EcoRunner;
use App\Entity\User;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoRunnerStatus;
use App\Enum\EcoScanResult;
use App\Form\EcoCourseType;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoParcoursRepository;
use App\Repository\EcoRunnerRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\EcoCourseCodeGenerator;
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

    // "Immobile depuis N min" threshold (screen 1h/4d) - a runner whose last known position is
    // older than this while still Racing shows as a stale-signal alert, same as one who's actively
    // backgrounded the app (EcoRunner::$appLeftAt).
    private const int STALE_SIGNAL_SECONDS = 240;

    #[Route(path: '/eco/courses/{id}/live', name: 'app_eco_course_live')]
    public function live(int $id, EcoCourseRepository $repository): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $this->sortedBySeverity($course->getRunners()->toArray());

        return $this->render('eco/course_live.html.twig', [
            'course' => $course,
            'rows' => array_map(fn (EcoRunner $runner): array => [...$this->runnerLiveRow($runner), 'runner' => $runner], $runners),
        ]);
    }

    // Polled every ~10s by assets/controllers/eco_live_controller.js (screen 1h's "rafraîchie
    // toutes les 10 s").
    #[Route(path: '/eco/courses/{id}/live/data', name: 'app_eco_course_live_data')]
    public function liveData(int $id, EcoCourseRepository $repository): JsonResponse
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $this->sortedBySeverity($course->getRunners()->toArray());

        return $this->json([
            'runners' => array_map(fn (EcoRunner $runner): array => $this->runnerLiveRow($runner), $runners),
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
    public function results(int $id, Request $request, EcoCourseRepository $repository, EcoRunnerStatsCalculator $statsCalculator): Response
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        $runners = $this->sortedByPseudo($course->getRunners()->toArray());

        $selectedRunner = null;
        $selectedId = $request->query->getInt('runner', 0);
        foreach ($runners as $runner) {
            if ($runner->getId() === $selectedId) {
                $selectedRunner = $runner;

                break;
            }
        }
        $selectedRunner ??= $runners[0] ?? null;

        return $this->render('eco/course_results.html.twig', [
            'course' => $course,
            'runners' => $runners,
            'selectedRunner' => $selectedRunner,
            'stats' => null !== $selectedRunner ? $statsCalculator->calculate($selectedRunner) : null,
        ]);
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
    private function sortedBySeverity(array $runners): array
    {
        usort($runners, fn (EcoRunner $a, EcoRunner $b): int => $this->severityRank($a) <=> $this->severityRank($b));

        return $runners;
    }

    /** @param list<EcoRunner> $runners
     * @return list<EcoRunner> */
    private function sortedByPseudo(array $runners): array
    {
        usort($runners, static fn (EcoRunner $a, EcoRunner $b): int => ($a->getPseudo() ?? '') <=> ($b->getPseudo() ?? ''));

        return $runners;
    }

    // 0 = SOS, 1 = stale signal while racing (no position update in STALE_SIGNAL_SECONDS, or
    // currently backgrounded), 2 = racing normally, 3 = finished/not started.
    private function severityRank(EcoRunner $runner): int
    {
        if ($runner->isSosActive()) {
            return 0;
        }
        if (EcoRunnerStatus::Racing === $runner->getStatus() && $this->isStale($runner)) {
            return 1;
        }
        if (EcoRunnerStatus::Racing === $runner->getStatus()) {
            return 2;
        }

        return 3;
    }

    private function isStale(EcoRunner $runner): bool
    {
        if (null !== $runner->getAppLeftAt()) {
            return true;
        }

        $lastPositionAt = $runner->getLastPositionAt();
        if (null === $lastPositionAt) {
            return true;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $lastPositionAt->getTimestamp() > self::STALE_SIGNAL_SECONDS;
    }

    /** @return array{id: int, pseudo: string, status: string, checkpointsValidated: int, checkpointsTotal: int, sosActive: bool, isStale: bool, lastSignalSeconds: ?int, appLeftSeconds: ?int} */
    private function runnerLiveRow(EcoRunner $runner): array
    {
        $now = new \DateTimeImmutable();
        $lastPositionAt = $runner->getLastPositionAt();
        $appLeftAt = $runner->getAppLeftAt();

        $validatedCount = \count(array_unique(array_map(
            static fn (EcoCheckpointScan $scan): int => $scan->getCheckpoint()->getId(),
            array_filter($runner->getScans()->toArray(), static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult()),
        )));

        return [
            'id' => $runner->getId(),
            'pseudo' => $runner->getPseudo() ?? '',
            'status' => $runner->getStatus()->value,
            'checkpointsValidated' => $validatedCount,
            'checkpointsTotal' => $runner->getCourse()->getParcours()->getCheckpoints()->count(),
            'sosActive' => $runner->isSosActive(),
            'isStale' => $this->isStale($runner),
            'lastSignalSeconds' => null !== $lastPositionAt ? $now->getTimestamp() - $lastPositionAt->getTimestamp() : null,
            'appLeftSeconds' => null !== $appLeftAt ? $now->getTimestamp() - $appLeftAt->getTimestamp() : null,
        ];
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
