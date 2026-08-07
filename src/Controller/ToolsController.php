<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The class picker of the tools reached from the top bar's "Outils" menu.
 *
 * Random draw, group creation, lesson log and gradebook only know how to work on one class: opened
 * from a class's submenu they get it from the URL; opened from the top bar it is missing, and this
 * screen asks for it before handing over to the tool itself. It is not an extra step inside the
 * class journey - coming from its submenu, one never passes through here.
 *
 * Audio recordings need none of this: their list is multi-class by nature, and it is their step 1
 * that asks for the class (App\Controller\AudioRecordingController). Neither do the tools that are
 * multi-class by nature and reached straight from the menu (progression, sequence and quiz
 * libraries, student work list).
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ToolsController extends AbstractController
{
    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    #[Route(path: '/tools/random-draw', name: 'app_tools_random_draw', methods: ['GET'])]
    public function randomDraw(ProgramRepository $repository): Response
    {
        return $this->renderPicker($repository, 'app_program_tools_random_draw', 'programToolsRandomDrawNavLabel');
    }

    #[Route(path: '/tools/group-creation', name: 'app_tools_group_creation', methods: ['GET'])]
    public function groupCreation(ProgramRepository $repository): Response
    {
        return $this->renderPicker($repository, 'app_program_tools_group_creation', 'programToolsGroupCreationNavLabel');
    }

    // Both of these live behind a class's timetable (a lesson log hangs off a LessonSession, a
    // gradebook off the evaluations of a Program that has one), so both target routes reject a class
    // whose timetable management is off - hence the filter, without which the picker would offer
    // classes that answer 404.
    #[Route(path: '/tools/lesson-log', name: 'app_tools_lesson_log', methods: ['GET'])]
    public function lessonLog(ProgramRepository $repository): Response
    {
        return $this->renderPicker($repository, 'app_program_lesson_logs', 'lessonLogPageHeading', timetableOnly: true);
    }

    #[Route(path: '/tools/gradebook', name: 'app_tools_gradebook', methods: ['GET'])]
    public function gradebook(ProgramRepository $repository): Response
    {
        return $this->renderPicker($repository, 'app_program_gradebook', 'gradebookNavLabel', timetableOnly: true);
    }

    /**
     * A single class taught: the question does not arise, straight through. This screen only shows up
     * when there really is a choice to make.
     */
    private function renderPicker(ProgramRepository $repository, string $route, string $headingKey, bool $timetableOnly = false): Response
    {
        $programs = $this->teachingPrograms($repository);

        if ($timetableOnly) {
            $programs = array_values(array_filter(
                $programs,
                static fn (Program $program): bool => $program->isTimetableManagementEnabled(),
            ));
        }

        if ([] === $programs) {
            throw $this->createAccessDeniedException();
        }

        if (1 === \count($programs)) {
            return $this->redirectToRoute($route, ['id' => $programs[0]->getId()]);
        }

        return $this->render('tools/class_picker.html.twig', [
            'programs' => $programs,
            'route' => $route,
            'headingKey' => $headingKey,
        ]);
    }

    /**
     * The classes these tools are offered for: the ones actually TAUGHT, not the ones one has the
     * right to look at. Every tool behind this picker produces a teacher's own work - their draw,
     * their groups, their lesson log, their marks - so offering a head of studies every class in
     * the school would mostly be offering them other people's.
     *
     * Whoever teaches nothing (administration, head of studies) keeps the full list, otherwise the
     * tools would simply vanish from their "Outils" menu.
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

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
