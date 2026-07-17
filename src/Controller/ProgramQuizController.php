<?php

namespace App\Controller;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Repository\QuizInstanceRepository;
use App\Security\StructureAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Program-scoped browsing of launched QuizInstances - see App\Entity\QuizInstance's class
// docblock. Gated by StructureAccessChecker::isProgramTeacher() (same as Outils > Tirage au
// sort), not ROLE_ADMIN-only like ProgramSequenceInstanceController: a quiz's launching teacher
// needs to see their own instances/results here too, not just staff. No dedicated mockup for this
// list screen (design/design_campus_manager/README.md only covers 1a-1p, not a "Program-side quiz
// instances index") - built inspired by ProgramSequenceInstanceController::list()/
// templates/program/sequences.html.twig instead, per the phased-build agreement.
class ProgramQuizController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz', name: 'app_program_quiz')]
    public function list(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/quiz_instances.html.twig', [
            'program' => $program,
            'quizInstances' => $instanceRepository->findForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}', name: 'app_program_quiz_show', requirements: ['instanceId' => '\d+'])]
    public function show(int $id, int $instanceId, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $instanceRepository->find($instanceId) ?? throw $this->createNotFoundException();

        if ($instance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/quiz_instance_show.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
        ]);
    }

    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
