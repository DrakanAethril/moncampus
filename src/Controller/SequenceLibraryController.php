<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Form\SeancePhaseTemplateType;
use App\Form\SeanceTemplateType;
use App\Form\SequenceInstantiateType;
use App\Form\SequenceTemplateType;
use App\Repository\ProgramRepository;
use App\Repository\SeancePhaseTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\SequenceInstantiationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A teacher's personal teaching-sequence library - see
// design/validated/teaching-sequence-library.md. Deliberately not Program-scoped (unlike most of
// this app's controllers): SequenceTemplate/SeanceTemplate/SeancePhaseTemplate are owned by a
// teacher, browsable only by that teacher (or staff, via SequenceTemplateVoter::EDIT) - there's no
// cross-teacher shared/discoverable library in this pass. Instantiating a template against a real
// Program is what bridges into the Program-scoped side (App\Controller\ProgramSequenceInstanceController).
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SequenceLibraryController extends AbstractController
{
    #[Route(path: '/library/sequences', name: 'app_library_sequences')]
    public function list(SequenceTemplateRepository $repository): Response
    {
        return $this->render('library/sequences.html.twig', [
            'sequenceTemplates' => $repository->findForTeacher($this->currentUser()),
        ]);
    }

    #[Route(path: '/library/sequences/new', name: 'app_library_sequences_new')]
    #[Route(path: '/library/sequences/{id}/edit', name: 'app_library_sequences_edit')]
    public function form(Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $repository, ?int $id = null): Response
    {
        $sequenceTemplate = null !== $id ? $this->findSequenceOrNotFound($repository, $id) : null;
        $isEdit = null !== $sequenceTemplate;

        if (!$isEdit) {
            $sequenceTemplate = new SequenceTemplate($this->currentUser());
        } else {
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        }

        $form = $this->createForm(SequenceTemplateType::class, $sequenceTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'sequenceTemplateUpdatedFlashMessage' : 'sequenceTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
        }

        return $this->render('library/sequence_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/library/sequences/{id}', name: 'app_library_sequences_show')]
    public function show(int $id, SequenceTemplateRepository $repository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);

        return $this->render('library/sequence_show.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'canEdit' => $this->isGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate),
        ]);
    }

    #[Route(path: '/library/sequences/{id}/remove', name: 'app_library_sequences_remove', methods: ['POST'])]
    public function remove(int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $repository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        if (!$this->isCsrfTokenValid('library_sequences_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($sequenceTemplate);
        $entityManager->flush();

        $this->addFlash('success', 'sequenceTemplateRemovedFlashMessage');

        return $this->redirectToRoute('app_library_sequences');
    }

    #[Route(path: '/library/sequences/{id}/instantiate', name: 'app_library_sequences_instantiate')]
    public function instantiate(int $id, Request $request, SequenceTemplateRepository $repository, StructureAccessChecker $accessChecker, ProgramRepository $programRepository, SequenceInstantiationService $instantiationService): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        $programs = $this->instantiablePrograms($accessChecker, $programRepository);
        $form = $this->createForm(SequenceInstantiateType::class, null, ['programs' => $programs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Program $program */
            $program = $form->get('program')->getData();
            $sequenceInstance = $instantiationService->instantiateSequence($sequenceTemplate, $program, $this->currentUser());

            $this->addFlash('success', 'sequenceInstantiatedFlashMessage');

            return $this->redirectToRoute('app_program_sequences_show', ['id' => $program->getId(), 'sequenceInstanceId' => $sequenceInstance->getId()]);
        }

        return $this->render('library/sequence_instantiate.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'form' => $form,
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/new', name: 'app_library_seances_new')]
    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}/edit', name: 'app_library_seances_edit')]
    public function seanceForm(int $sequenceId, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, ?int $id = null): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        $seanceTemplate = null !== $id ? $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id) : null;
        $isEdit = null !== $seanceTemplate;

        if (!$isEdit) {
            $seanceTemplate = new SeanceTemplate($sequenceTemplate);
            $seanceTemplate->setOrdre(\count($sequenceTemplate->getSeanceTemplates()) + 1);
        }

        $form = $this->createForm(SeanceTemplateType::class, $seanceTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'seanceTemplateUpdatedFlashMessage' : 'seanceTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
        }

        return $this->render('library/seance_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'sequenceTemplate' => $sequenceTemplate,
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}', name: 'app_library_seances_show')]
    public function seanceShow(int $sequenceId, int $id, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);

        return $this->render('library/seance_show.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
            'canEdit' => $this->isGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate),
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}/remove', name: 'app_library_seances_remove', methods: ['POST'])]
    public function seanceRemove(int $sequenceId, int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);

        if (!$this->isCsrfTokenValid('library_seances_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($seanceTemplate);
        $entityManager->flush();

        $this->addFlash('success', 'seanceTemplateRemovedFlashMessage');

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}/instantiate', name: 'app_library_seances_instantiate')]
    public function seanceInstantiate(int $sequenceId, int $id, Request $request, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, StructureAccessChecker $accessChecker, ProgramRepository $programRepository, SequenceInstantiationService $instantiationService): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);

        $programs = $this->instantiablePrograms($accessChecker, $programRepository);
        $form = $this->createForm(SequenceInstantiateType::class, null, ['programs' => $programs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Program $program */
            $program = $form->get('program')->getData();
            $seanceInstance = $instantiationService->instantiateStandaloneSeance($seanceTemplate, $program, $this->currentUser());

            $this->addFlash('success', 'seanceInstantiatedFlashMessage');

            return $this->redirectToRoute('app_program_sequences', ['id' => $program->getId(), '_fragment' => 'seance-'.$seanceInstance->getId()]);
        }

        return $this->render('library/seance_instantiate.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
            'form' => $form,
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/new', name: 'app_library_phases_new')]
    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/{id}/edit', name: 'app_library_phases_edit')]
    public function phaseForm(int $sequenceId, int $seanceId, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, SeancePhaseTemplateRepository $phaseRepository, ?int $id = null): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $seanceId);

        $phaseTemplate = null !== $id ? $this->findPhaseOrNotFound($phaseRepository, $seanceTemplate, $id) : null;
        $isEdit = null !== $phaseTemplate;

        if (!$isEdit) {
            $phaseTemplate = new SeancePhaseTemplate($seanceTemplate);
            $phaseTemplate->setOrdre(\count($seanceTemplate->getSeancePhaseTemplates()) + 1);
        }

        $form = $this->createForm(SeancePhaseTemplateType::class, $phaseTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'seancePhaseTemplateUpdatedFlashMessage' : 'seancePhaseTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
        }

        return $this->render('library/phase_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/{id}/remove', name: 'app_library_phases_remove', methods: ['POST'])]
    public function phaseRemove(int $sequenceId, int $seanceId, int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, SeancePhaseTemplateRepository $phaseRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $seanceId);
        $phaseTemplate = $this->findPhaseOrNotFound($phaseRepository, $seanceTemplate, $id);

        if (!$this->isCsrfTokenValid('library_phases_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($phaseTemplate);
        $entityManager->flush();

        $this->addFlash('success', 'seancePhaseTemplateRemovedFlashMessage');

        return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
    }

    // Only Programs with the timetable feature on are offered - instantiating against one
    // without it would create SeanceInstances that can never be scheduled (schedule() and every
    // other action in ProgramSequenceInstanceController require it), a dead end better prevented
    // here than discovered later.
    /** @return list<Program> */
    private function instantiablePrograms(StructureAccessChecker $accessChecker, ProgramRepository $programRepository): array
    {
        $programs = $accessChecker->isStaff()
            ? $programRepository->findAll()
            : $programRepository->findAllForTeacher($this->currentUser());

        return array_values(array_filter($programs, static fn (Program $program): bool => $program->isTimetableManagementEnabled()));
    }

    private function findSequenceOrNotFound(SequenceTemplateRepository $repository, int $id): SequenceTemplate
    {
        $sequenceTemplate = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($sequenceTemplate->getTeacher() !== $this->currentUser() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_STAFF-LEAD')) {
            throw $this->createNotFoundException();
        }

        return $sequenceTemplate;
    }

    private function findSeanceOrNotFound(SeanceTemplateRepository $repository, SequenceTemplate $sequenceTemplate, int $id): SeanceTemplate
    {
        $seanceTemplate = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($seanceTemplate->getSequenceTemplate()->getId() !== $sequenceTemplate->getId()) {
            throw $this->createNotFoundException();
        }

        return $seanceTemplate;
    }

    private function findPhaseOrNotFound(SeancePhaseTemplateRepository $repository, SeanceTemplate $seanceTemplate, int $id): SeancePhaseTemplate
    {
        $phaseTemplate = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($phaseTemplate->getSeanceTemplate()->getId() !== $seanceTemplate->getId()) {
            throw $this->createNotFoundException();
        }

        return $phaseTemplate;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
