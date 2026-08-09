<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\SequenceInstanceRemover;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Read-only, Program-scoped browsing of instantiated séquences/séances (SequenceInstance/
// SeanceInstance) - see design/validated/teaching-sequence-library.md. Restricted to ROLE_ADMIN
// only (unlike the Bibliothèque/library side in SequenceLibraryController, which stays open to
// teachers/staff too) - the program visibility/staff-or-creator checks below predate this and are
// now always true for an admin, but are left in place since they're still correct, just no longer
// reachable by anyone else.
//
// This controller used to also carry a "schedule" step that CREATED a LessonSession out of a
// SeanceInstance. It was removed once the Progression pédagogique module shipped: a teacher now
// associates séances with créneaux that already exist in the timetable rather than conjuring new
// ones, and creating a LessonSession went back to being staff-owned. The scheduled/unscheduled
// badges below still mean something - App\Service\ProgressionPlacementService::validate() is what
// writes SeanceInstance::$lessonSession now.
#[IsGranted('ROLE_ADMIN')]
class ProgramSequenceInstanceController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/sequences', name: 'app_program_sequences')]
    public function list(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, SequenceInstanceRepository $sequenceInstanceRepository, SeanceInstanceRepository $seanceInstanceRepository, ProgressionSeancePlacementRepository $placementRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        // "Programmée" is asked of the progression's placements, and of nothing else.
        //
        // SeanceInstance::$lessonSession cannot answer it: it is a unique OneToOne written at
        // validation time, so it names at most one créneau (a séance duplicated per group looked
        // unscheduled through it), it only ever changes when someone validates again, and nothing
        // that unplans a séance clears it - dropping a séquence from a progression left every one
        // of its séances still counted as scheduled here. The placements have none of those
        // problems: they exist exactly while the séance is planned.
        $scheduledIds = $placementRepository->findScheduledSeanceInstanceIdsForProgram($program);

        return $this->render('program/sequences.html.twig', [
            'program' => $program,
            'sequenceInstances' => $sequenceInstanceRepository->findForProgram($program),
            'standaloneSeanceInstances' => $seanceInstanceRepository->findStandaloneForProgram($program),
            'scheduledSeanceInstanceIds' => $scheduledIds,
        ]);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}', name: 'app_program_sequences_show', requirements: ['sequenceInstanceId' => '\d+'])]
    public function show(int $id, int $sequenceInstanceId, ProgramRepository $repository, StructureAccessChecker $accessChecker, SequenceInstanceRepository $sequenceInstanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $sequenceInstance = $sequenceInstanceRepository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();

        if ($sequenceInstance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/sequence_instance_show.html.twig', [
            'program' => $program,
            'sequenceInstance' => $sequenceInstance,
        ]);
    }

    /**
     * Undoes an instantiation: the séquence copied for this class goes, and with it the progression
     * rows that planned it - so the créneaux it held are freed and the séquences that followed it
     * slide up (see App\Service\SequenceInstanceRemover for what is deliberately left alone).
     *
     * POST + CSRF + a confirm() on the button: this deletes frozen pedagogical content that cannot
     * be rebuilt from the library template, since the template may have moved on since.
     */
    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/remove', name: 'app_program_sequences_remove', methods: ['POST'], requirements: ['sequenceInstanceId' => '\d+'])]
    public function remove(int $id, int $sequenceInstanceId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, SequenceInstanceRepository $sequenceInstanceRepository, SequenceInstanceRemover $remover): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $sequenceInstance = $sequenceInstanceRepository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();

        // Re-checked against the Program in the URL rather than trusted from the id, same as show().
        if ($sequenceInstance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('program_sequence_instance_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $remover->remove($sequenceInstance);

        $this->addFlash('success', 'programSequencesRemovedFlashMessage');

        return $this->redirectToRoute('app_program_sequences', ['id' => $program->getId()]);
    }

    // Students/teachers see every séquence for a Program they're visible in (same rule as the
    // timetable/lesson-log read side) - staff always, others per StructureAccessChecker. Also
    // requires timetableManagementEnabled, same as LessonLogController - a SeanceInstance only
    // ever becomes useful by eventually backing a real LessonSession, so a Program with the
    // timetable feature off is a dead end for this whole area, not just a display quirk.
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }
}
