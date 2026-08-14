<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\SequenceInstance;
use App\Form\SeanceInstanceType;
use App\Form\SeancePhaseInstanceType;
use App\Repository\ProgramRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SeancePhaseInstanceRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceInstanceVoter;
use App\Service\FormValue;
use App\Service\ProgressionSeanceSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La fiche d'une séance telle que CETTE CLASSE la reçoit, et son déroulé.
 *
 * The library already had a séance sheet (SequenceLibraryController::seanceShow()), but it
 * describes the TEMPLATE: what it shows is whatever the model says today, which is not necessarily
 * what this class was taught, and editing it there rewrites the model for every class that
 * instantiated it. This controller is the copy's own side - the same sheet, reading and writing the
 * SeanceInstance and its SeancePhaseInstances.
 *
 * Every write screen carries templates/program/_instance_edit_banner.html.twig. That banner is not
 * decoration: the two sheets look alike by design (see SeanceInstanceType), so it is the only thing
 * standing between "I corrected this for my class" and "I have just rewritten my library".
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramSeanceInstanceController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly SequenceInstanceRepository $sequenceInstanceRepository,
        private readonly SeanceInstanceRepository $seanceInstanceRepository,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/sessions/{seanceInstanceId}', name: 'app_program_seances_show', requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+'])]
    public function show(int $id, int $sequenceInstanceId, int $seanceInstanceId): Response
    {
        [$program, $sequenceInstance, $seanceInstance] = $this->resolve($id, $sequenceInstanceId, $seanceInstanceId);

        return $this->render('program/seance_instance_show.html.twig', [
            'program' => $program,
            'sequenceInstance' => $sequenceInstance,
            'seanceInstance' => $seanceInstance,
            'phases' => $this->orderedPhases($seanceInstance),
            'mayEdit' => $this->isGranted(SequenceInstanceVoter::EDIT, $sequenceInstance),
        ]);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/sessions/{seanceInstanceId}/edit', name: 'app_program_seances_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+'])]
    public function edit(int $id, int $sequenceInstanceId, int $seanceInstanceId, Request $request, ProgressionSeanceSynchronizer $synchronizer): Response
    {
        [$program, $sequenceInstance, $seanceInstance] = $this->resolve($id, $sequenceInstanceId, $seanceInstanceId);
        $this->denyAccessUnlessGranted(SequenceInstanceVoter::EDIT, $sequenceInstance);

        $form = $this->createForm(SeanceInstanceType::class, $seanceInstance);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // "Cochée sans nature" must not reach the database - same rule as the template side, and
            // the reason the checkbox is unmapped.
            if (!FormValue::bool($form, 'hasEvaluation')) {
                $seanceInstance->setEvaluationNature(null);
            }

            // The progression copied this nature when it planned the séance, so correcting it here
            // has to reach the plan too - otherwise the progression's D/F/S counters and the
            // Qualiopi export keep stating the nature the séance no longer has.
            $synchronizer->syncEvaluationNature($seanceInstance);

            $this->entityManager->flush();
            $this->addFlash('success', 'seanceInstanceSavedFlashMessage');

            return $this->redirectToRoute('app_program_seances_show', [
                'id' => $program->getId(),
                'sequenceInstanceId' => $sequenceInstance->getId(),
                'seanceInstanceId' => $seanceInstance->getId(),
            ]);
        }

        return $this->render('program/seance_instance_edit.html.twig', [
            'program' => $program,
            'sequenceInstance' => $sequenceInstance,
            'seanceInstance' => $seanceInstance,
            'form' => $form,
        ]);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/sessions/{seanceInstanceId}/phases/new', name: 'app_program_phases_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+'])]
    public function phaseNew(int $id, int $sequenceInstanceId, int $seanceInstanceId, Request $request): Response
    {
        [$program, $sequenceInstance, $seanceInstance] = $this->resolve($id, $sequenceInstanceId, $seanceInstanceId);
        $this->denyAccessUnlessGranted(SequenceInstanceVoter::EDIT, $sequenceInstance);

        $phase = new SeancePhaseInstance($seanceInstance);
        $phase->setOrdre($this->nextPhaseOrder($seanceInstance));

        return $this->phaseForm($request, $program, $sequenceInstance, $seanceInstance, $phase, isNew: true);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/sessions/{seanceInstanceId}/phases/{phaseId}/edit', name: 'app_program_phases_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+', 'phaseId' => '\d+'])]
    public function phaseEdit(int $id, int $sequenceInstanceId, int $seanceInstanceId, int $phaseId, Request $request, SeancePhaseInstanceRepository $phaseRepository): Response
    {
        [$program, $sequenceInstance, $seanceInstance] = $this->resolve($id, $sequenceInstanceId, $seanceInstanceId);
        $this->denyAccessUnlessGranted(SequenceInstanceVoter::EDIT, $sequenceInstance);

        $phase = $this->findPhaseOrNotFound($phaseRepository, $seanceInstance, $phaseId);

        return $this->phaseForm($request, $program, $sequenceInstance, $seanceInstance, $phase, isNew: false);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/sessions/{seanceInstanceId}/phases/{phaseId}/remove', name: 'app_program_phases_remove', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+', 'phaseId' => '\d+'])]
    public function phaseRemove(int $id, int $sequenceInstanceId, int $seanceInstanceId, int $phaseId, Request $request, SeancePhaseInstanceRepository $phaseRepository): Response
    {
        [$program, $sequenceInstance, $seanceInstance] = $this->resolve($id, $sequenceInstanceId, $seanceInstanceId);
        $this->denyAccessUnlessGranted(SequenceInstanceVoter::EDIT, $sequenceInstance);

        if (!$this->isCsrfTokenValid('seance_phase_instance_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($this->findPhaseOrNotFound($phaseRepository, $seanceInstance, $phaseId));
        $this->entityManager->flush();

        $this->addFlash('success', 'seancePhaseInstanceRemovedFlashMessage');

        return $this->redirectToRoute('app_program_seances_show', [
            'id' => $program->getId(),
            'sequenceInstanceId' => $sequenceInstance->getId(),
            'seanceInstanceId' => $seanceInstance->getId(),
        ]);
    }

    private function phaseForm(Request $request, Program $program, SequenceInstance $sequenceInstance, SeanceInstance $seanceInstance, SeancePhaseInstance $phase, bool $isNew): Response
    {
        $form = $this->createForm(SeancePhaseInstanceType::class, $phase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->entityManager->persist($phase);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'seancePhaseInstanceSavedFlashMessage');

            return $this->redirectToRoute('app_program_seances_show', [
                'id' => $program->getId(),
                'sequenceInstanceId' => $sequenceInstance->getId(),
                'seanceInstanceId' => $seanceInstance->getId(),
            ]);
        }

        return $this->render('program/phase_instance_edit.html.twig', [
            'program' => $program,
            'sequenceInstance' => $sequenceInstance,
            'seanceInstance' => $seanceInstance,
            'phase' => $phase,
            'isNew' => $isNew,
            'form' => $form,
        ]);
    }

    /**
     * The three ids of every route here, each re-checked against the one above it rather than
     * trusted - a séance id alone would otherwise reach a séance of another class's séquence.
     *
     * @return array{0: Program, 1: SequenceInstance, 2: SeanceInstance}
     */
    private function resolve(int $programId, int $sequenceInstanceId, int $seanceInstanceId): array
    {
        $program = $this->findOrDenyAccess($programId);

        $sequenceInstance = $this->sequenceInstanceRepository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();
        if ($sequenceInstance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SequenceInstanceVoter::VIEW, $sequenceInstance);

        $seanceInstance = $this->seanceInstanceRepository->find($seanceInstanceId) ?? throw $this->createNotFoundException();
        if ($seanceInstance->getSequenceInstance()?->getId() !== $sequenceInstance->getId()) {
            throw $this->createNotFoundException();
        }

        return [$program, $sequenceInstance, $seanceInstance];
    }

    private function findPhaseOrNotFound(SeancePhaseInstanceRepository $repository, SeanceInstance $seanceInstance, int $phaseId): SeancePhaseInstance
    {
        $phase = $repository->find($phaseId) ?? throw $this->createNotFoundException();

        if ($phase->getSeanceInstance()?->getId() !== $seanceInstance->getId()) {
            throw $this->createNotFoundException();
        }

        return $phase;
    }

    /** @return list<SeancePhaseInstance> */
    private function orderedPhases(SeanceInstance $seanceInstance): array
    {
        $phases = $seanceInstance->getSeancePhaseInstances()->toArray();
        // usort() reindexes, so the result is already the list the return type promises.
        usort($phases, static fn (SeancePhaseInstance $a, SeancePhaseInstance $b): int => $a->getOrdre() <=> $b->getOrdre());

        return $phases;
    }

    private function nextPhaseOrder(SeanceInstance $seanceInstance): int
    {
        $highest = -1;
        foreach ($seanceInstance->getSeancePhaseInstances() as $phase) {
            $highest = max($highest, $phase->getOrdre());
        }

        return $highest + 1;
    }

    // Same rule as ProgramSequenceInstanceController::findOrDenyAccess(), which each Program-scoped
    // controller states for itself in this codebase.
    private function findOrDenyAccess(int $id): Program
    {
        $program = $this->programRepository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }
}
