<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Entity\LearningPath;
use App\Enum\Feature;
use App\Enum\LearningPathStepState;
use App\Repository\LearningPathRepository;
use App\Security\Voter\LearningPathVoter;
use App\Service\LearningPath\LearningPathTracking;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The follow-up of a learning path (design/validated/cours-en-ligne.md, §11): who started it, when,
 * how far each person is, and - one row unfolded - every step they opened and every attempt they
 * made.
 *
 * **Read by the path's author and by nobody else, administrators included**
 * (App\Security\Voter\LearningPathVoter::TRACK): it names people and their scores. Anybody else is
 * answered the 404 of a path that does not exist. The person followed is told so on the path's own
 * page, and the Claude connector has no tool that reads this.
 */
#[RequiresFeature(Feature::OnlineCourses)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class PathTrackingController extends AbstractController
{
    #[Route(path: '/tools/online-courses/paths/{id}/tracking', name: 'app_online_courses_path_tracking', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, Request $request, LearningPathRepository $paths, LearningPathTracking $tracking): Response
    {
        $path = $this->findPath($id, $paths);

        $classId = QueryValue::nullableInt($request, 'class');
        $sort = QueryValue::trimmed($request, 'sort', 'started');
        $sort = \in_array($sort, LearningPathTracking::SORTS, true) ? $sort : 'started';
        $descending = 'desc' === QueryValue::trimmed($request, 'dir');

        $all = $tracking->rows($path);
        $rows = $tracking->rows($path, $classId, $sort, $descending);

        return $this->render('online_course/authoring/path_tracking.html.twig', [
            'path' => $path,
            'rows' => $rows,
            'startedCount' => \count($all),
            'completedCount' => \count(array_filter($all, static fn (array $row): bool => $row['progress']->completed)),
            'classes' => $tracking->classesOf($all),
            'classFilter' => $classId,
            'sort' => $sort,
            'descending' => $descending,
        ]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/tracking.csv', name: 'app_online_courses_path_tracking_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(int $id, Request $request, LearningPathRepository $paths, LearningPathTracking $tracking): Response
    {
        $path = $this->findPath($id, $paths);
        $rows = $tracking->rows($path, QueryValue::nullableInt($request, 'class'), 'name');

        $steps = $path->orderedSteps();
        $header = ['Personne', 'Classe', 'Commencé le', 'Étapes faites', 'Étapes', 'Quiz validés', 'Quiz', 'Dernière activité', 'Terminé le'];
        foreach ($steps as $index => $step) {
            $header[] = \sprintf('%d. %s', $index + 1, $step->getTitle());
        }

        $lines = [$header];
        foreach ($rows as $row) {
            $progress = $row['progress'];
            $enrollment = $progress->enrollment;
            $line = [
                $row['name'],
                implode(', ', $row['classes']),
                $enrollment?->getStartedAt()->format('d/m/Y') ?? '',
                (string) $progress->doneCount(),
                (string) $progress->totalCount(),
                (string) $progress->validatedQuizCount(),
                (string) $progress->quizCount(),
                $enrollment?->getLastActivityAt()->format('d/m/Y') ?? '',
                $enrollment?->getCompletedAt()?->format('d/m/Y') ?? '',
            ];

            foreach ($progress->steps as $view) {
                $line[] = match (true) {
                    LearningPathStepState::Unavailable === $view->state => 'indisponible',
                    $view->step->isQuiz() => null === $view->bestScore ? '' : \sprintf('%d %% (%d tentative(s))', $view->bestScore, \count($view->finishedAttempts())),
                    default => $view->openedAt?->format('d/m/Y') ?? '',
                };
            }

            $lines[] = $line;
        }

        $stream = fopen('php://temp', 'w+') ?: throw new \RuntimeException('Could not open a temporary stream.');
        // A BOM and semicolons: the file is opened in a French Excel far more often than parsed.
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($stream, $line, ';', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $response = new Response($csv, Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('suivi-parcours-%d.csv', (int) $path->getId()),
        ));

        return $response;
    }

    private function findPath(int $id, LearningPathRepository $paths): LearningPath
    {
        $path = $paths->find($id);
        if (null === $path || !$this->isGranted(LearningPathVoter::TRACK, $path)) {
            throw $this->createNotFoundException();
        }

        return $path;
    }
}
