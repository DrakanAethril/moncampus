<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Service\QueryValue;
use App\Service\Wall\StudentWallDirectory;
use App\Service\Wall\WallTimeLabel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Murs étudiants » (Ressources, on a teacher's bar): the walls of the students of a class one
 * teaches - first the class, then its students' own walls and its groups' walls.
 *
 * The screen only lists; opening a wall goes through the wall's own door, which asks
 * App\Service\Wall\WallAccess again and lets a teacher of the class in as a reader. It offers the
 * classes one teaches and no other: whoever teaches none - the administration included - has no
 * student's wall to read, and the screen does not exist for somebody who is not a teacher.
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted('ROLE_TEACHER', statusCode: 404)]
class StudentWallController extends AbstractController
{
    use WallControllerTrait;

    // One class: straight to it. Several: the choice - which the select at the top of a class's
    // list also posts here, as `?class=`.
    #[Route(path: '/walls/students', name: 'app_wall_students', methods: ['GET'])]
    public function index(Request $request, ProgramRepository $programs): Response
    {
        $classes = $programs->findAllForTeacher($this->currentUser());
        $chosen = QueryValue::nullableInt($request, 'class');
        if (null !== $chosen) {
            return $this->redirectToRoute('app_wall_students_class', ['id' => $chosen]);
        }
        if (1 === \count($classes)) {
            return $this->redirectToRoute('app_wall_students_class', ['id' => $classes[0]->getId()]);
        }

        return $this->render('wall/students/index.html.twig', ['classes' => $classes]);
    }

    #[Route(path: '/walls/students/{id}', name: 'app_wall_students_class', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ProgramRepository $programs, StudentWallDirectory $directory, WallTimeLabel $timeLabel): Response
    {
        $classes = $programs->findAllForTeacher($this->currentUser());
        $class = array_values(array_filter($classes, static fn (Program $program): bool => $program->getId() === $id))[0] ?? throw $this->createNotFoundException();
        $walls = $directory->forClass($class);

        $now = new \DateTimeImmutable();
        $modified = [];
        foreach ([...array_merge(...array_column($walls['individual'], 'walls')), ...array_column($walls['groups'], 'wall')] as $wall) {
            $modified[(int) $wall->getId()] = $timeLabel->ago($wall->getUpdatedAt(), $now);
        }

        return $this->render('wall/students/show.html.twig', [
            'class' => $class,
            'classes' => $classes,
            'walls' => $walls,
            'modified' => $modified,
        ]);
    }
}
