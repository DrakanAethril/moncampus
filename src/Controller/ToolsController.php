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
 * Random draw and group creation only know how to work on one class: opened from a class's submenu
 * they get it from the URL; opened from the top bar it is missing, and this screen asks for it
 * before handing over to the tool itself. It is not an extra step inside the class journey - coming
 * from its submenu, one never passes through here.
 *
 * Audio recordings need none of this: their list is multi-class by nature, and it is their step 1
 * that asks for the class (App\Controller\AudioRecordingController).
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

    /**
     * A single class taught: the question does not arise, straight through. This screen only shows up
     * when there really is a choice to make.
     */
    private function renderPicker(ProgramRepository $repository, string $route, string $headingKey): Response
    {
        $programs = $this->teachingPrograms($repository);

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

    /** @return list<Program> */
    private function teachingPrograms(ProgramRepository $repository): array
    {
        $viewer = $this->currentUser();

        return $this->accessChecker->isStaff()
            ? $repository->findActiveForNav($viewer)
            : $repository->findAllForTeacher($viewer);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
