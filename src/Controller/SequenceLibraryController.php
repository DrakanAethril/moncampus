<?php

namespace App\Controller;

use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\LibraryResource;
use App\Entity\Program;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\LibraryResourceSourceType;
use App\Form\LibraryResourceType;
use App\Form\SeancePhaseTemplateType;
use App\Form\SeanceTemplateType;
use App\Form\SequenceInstantiateType;
use App\Form\SequenceTemplateType;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Repository\LibraryResourceRepository;
use App\Repository\ProgramRepository;
use App\Repository\SeancePhaseTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\FileUploadService;
use App\Service\LibraryTagResolver;
use App\Service\SequenceInstantiationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    private const string RESOURCE_UPLOAD_PREFIX = 'library-resources/';

    #[Route(path: '/library/sequences', name: 'app_library_sequences')]
    public function list(Request $request, SequenceTemplateRepository $repository, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $teacher = $this->currentUser();
        // Not query->getInt(): it throws on the empty string an unselected filter submits, rather
        // than treating it like "not provided".
        $niveauId = '' !== $request->query->get('niveau', '') ? $request->query->getInt('niveau') : null;
        $optionId = '' !== $request->query->get('option', '') ? $request->query->getInt('option') : null;
        $blocId = '' !== $request->query->get('bloc', '') ? $request->query->getInt('bloc') : null;

        $niveau = null !== $niveauId ? $niveauTagRepository->find($niveauId) : null;
        $option = null !== $optionId ? $optionTagRepository->find($optionId) : null;
        $bloc = null !== $blocId ? $blocTagRepository->find($blocId) : null;

        return $this->render('library/sequences.html.twig', [
            'sequenceTemplates' => $repository->findForTeacher($teacher, $niveau, $option, $bloc),
            'tagOptions' => $this->libraryTagOptions($niveauTagRepository, $optionTagRepository, $blocTagRepository),
            'selectedNiveauId' => $niveau?->getId(),
            'selectedOptionId' => $option?->getId(),
            'selectedBlocId' => $bloc?->getId(),
        ]);
    }

    #[Route(path: '/library/sequences/new', name: 'app_library_sequences_new')]
    #[Route(path: '/library/sequences/{id}/edit', name: 'app_library_sequences_edit')]
    public function form(Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $repository, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository, ?int $id = null): Response
    {
        $sequenceTemplate = null !== $id ? $this->findSequenceOrNotFound($repository, $id) : null;
        $isEdit = null !== $sequenceTemplate;

        if (!$isEdit) {
            $sequenceTemplate = new SequenceTemplate($this->currentUser());
            $sequenceTemplate->setOrder(\count($repository->findForTeacher($this->currentUser())) + 1);
        } else {
            $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        }

        $form = $this->createForm(SequenceTemplateType::class, $sequenceTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyTags($sequenceTemplate, $request, $tagResolver, $niveauTagRepository, $optionTagRepository, $blocTagRepository);

            $entityManager->persist($sequenceTemplate);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'sequenceTemplateUpdatedFlashMessage' : 'sequenceTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
        }

        return $this->render('library/sequence_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'tagOptions' => $this->libraryTagOptions($niveauTagRepository, $optionTagRepository, $blocTagRepository),
            'currentNiveauLabel' => $sequenceTemplate->getNiveau()?->getLabel(),
            'currentOptionLabel' => $sequenceTemplate->getOption()?->getLabel(),
            'currentBlocLabels' => array_map(static fn (LibraryBlocTag $bloc): string => $bloc->getLabel(), $sequenceTemplate->getBlocs()->toArray()),
        ]);
    }

    #[Route(path: '/library/sequences/{id}', name: 'app_library_sequences_show')]
    public function show(int $id, SequenceTemplateRepository $repository, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);
        $canEdit = $this->isGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        return $this->render('library/sequence_show.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'canEdit' => $canEdit,
            'resourceForm' => $canEdit ? $this->createForm(LibraryResourceType::class) : null,
            'tagOptions' => $this->libraryTagOptions($niveauTagRepository, $optionTagRepository, $blocTagRepository),
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

    #[Route(path: '/library/sequences/{id}/resources', name: 'app_library_sequences_resources_new', methods: ['POST'])]
    public function sequenceResourceAdd(int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $repository, FileUploadService $fileUploadService, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        $this->handleResourceForm($request, $entityManager, $fileUploadService, $tagResolver, $niveauTagRepository, $optionTagRepository, $blocTagRepository, static function (LibraryResource $resource) use ($sequenceTemplate): void {
            $resource->setSequenceTemplate($sequenceTemplate);
        });

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
    }

    #[Route(path: '/library/sequences/{id}/resources/{resourceId}/delete', name: 'app_library_sequences_resources_delete', methods: ['POST'])]
    public function sequenceResourceDelete(int $id, int $resourceId, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $repository, LibraryResourceRepository $resourceRepository, FileUploadService $fileUploadService): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        $resource = $resourceRepository->find($resourceId) ?? throw $this->createNotFoundException();
        if ($resource->getSequenceTemplate()?->getId() !== $sequenceTemplate->getId()) {
            throw $this->createNotFoundException();
        }

        $this->deleteResource($resource, $request, $entityManager, $fileUploadService);

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
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

            // The Program-side page is ROLE_ADMIN-only (App\Controller\ProgramSequenceInstanceController) -
            // a teacher who isn't one can still instantiate here, but has nowhere to view the
            // result, so send them back to the template they instantiated from instead.
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('app_program_sequences_show', ['id' => $program->getId(), 'sequenceInstanceId' => $sequenceInstance->getId()]);
            }

            return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequenceTemplate->getId()]);
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
    public function seanceShow(int $sequenceId, int $id, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);
        $canEdit = $this->isGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        return $this->render('library/seance_show.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
            'canEdit' => $canEdit,
            'resourceForm' => $canEdit ? $this->createForm(LibraryResourceType::class) : null,
            'tagOptions' => $this->libraryTagOptions($niveauTagRepository, $optionTagRepository, $blocTagRepository),
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

    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}/resources', name: 'app_library_seances_resources_new', methods: ['POST'])]
    public function seanceResourceAdd(int $sequenceId, int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, FileUploadService $fileUploadService, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);

        $this->handleResourceForm($request, $entityManager, $fileUploadService, $tagResolver, $niveauTagRepository, $optionTagRepository, $blocTagRepository, static function (LibraryResource $resource) use ($seanceTemplate): void {
            $resource->setSeanceTemplate($seanceTemplate);
        });

        return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{id}/resources/{resourceId}/delete', name: 'app_library_seances_resources_delete', methods: ['POST'])]
    public function seanceResourceDelete(int $sequenceId, int $id, int $resourceId, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, LibraryResourceRepository $resourceRepository, FileUploadService $fileUploadService): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $id);

        $resource = $resourceRepository->find($resourceId) ?? throw $this->createNotFoundException();
        if ($resource->getSeanceTemplate()?->getId() !== $seanceTemplate->getId()) {
            throw $this->createNotFoundException();
        }

        $this->deleteResource($resource, $request, $entityManager, $fileUploadService);

        return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
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

            // Same reasoning as instantiate() above - the Program-side page is ROLE_ADMIN-only,
            // so a non-admin goes back to the séance template instead.
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('app_program_sequences', ['id' => $program->getId(), '_fragment' => 'seance-'.$seanceInstance->getId()]);
            }

            return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequenceTemplate->getId(), 'id' => $seanceTemplate->getId()]);
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

            return $this->redirectToRoute('app_library_phases_show', ['sequenceId' => $sequenceTemplate->getId(), 'seanceId' => $seanceTemplate->getId(), 'id' => $phaseTemplate->getId()]);
        }

        return $this->render('library/phase_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
        ]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/{id}', name: 'app_library_phases_show')]
    public function phaseShow(int $sequenceId, int $seanceId, int $id, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, SeancePhaseTemplateRepository $phaseRepository, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $seanceId);
        $phaseTemplate = $this->findPhaseOrNotFound($phaseRepository, $seanceTemplate, $id);
        $canEdit = $this->isGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);

        return $this->render('library/phase_show.html.twig', [
            'sequenceTemplate' => $sequenceTemplate,
            'seanceTemplate' => $seanceTemplate,
            'phaseTemplate' => $phaseTemplate,
            'canEdit' => $canEdit,
            'resourceForm' => $canEdit ? $this->createForm(LibraryResourceType::class) : null,
            'tagOptions' => $this->libraryTagOptions($niveauTagRepository, $optionTagRepository, $blocTagRepository),
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

    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/{id}/resources', name: 'app_library_phases_resources_new', methods: ['POST'])]
    public function phaseResourceAdd(int $sequenceId, int $seanceId, int $id, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, SeancePhaseTemplateRepository $phaseRepository, FileUploadService $fileUploadService, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $seanceId);
        $phaseTemplate = $this->findPhaseOrNotFound($phaseRepository, $seanceTemplate, $id);

        $this->handleResourceForm($request, $entityManager, $fileUploadService, $tagResolver, $niveauTagRepository, $optionTagRepository, $blocTagRepository, static function (LibraryResource $resource) use ($phaseTemplate): void {
            $resource->setSeancePhaseTemplate($phaseTemplate);
        });

        return $this->redirectToRoute('app_library_phases_show', ['sequenceId' => $sequenceTemplate->getId(), 'seanceId' => $seanceTemplate->getId(), 'id' => $phaseTemplate->getId()]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/seances/{seanceId}/phases/{id}/resources/{resourceId}/delete', name: 'app_library_phases_resources_delete', methods: ['POST'])]
    public function phaseResourceDelete(int $sequenceId, int $seanceId, int $id, int $resourceId, Request $request, EntityManagerInterface $entityManager, SequenceTemplateRepository $sequenceRepository, SeanceTemplateRepository $seanceRepository, SeancePhaseTemplateRepository $phaseRepository, LibraryResourceRepository $resourceRepository, FileUploadService $fileUploadService): Response
    {
        $sequenceTemplate = $this->findSequenceOrNotFound($sequenceRepository, $sequenceId);
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequenceTemplate);
        $seanceTemplate = $this->findSeanceOrNotFound($seanceRepository, $sequenceTemplate, $seanceId);
        $phaseTemplate = $this->findPhaseOrNotFound($phaseRepository, $seanceTemplate, $id);

        $resource = $resourceRepository->find($resourceId) ?? throw $this->createNotFoundException();
        if ($resource->getSeancePhaseTemplate()?->getId() !== $phaseTemplate->getId()) {
            throw $this->createNotFoundException();
        }

        $this->deleteResource($resource, $request, $entityManager, $fileUploadService);

        return $this->redirectToRoute('app_library_phases_show', ['sequenceId' => $sequenceTemplate->getId(), 'seanceId' => $seanceTemplate->getId(), 'id' => $phaseTemplate->getId()]);
    }

    // Shared by the sequence/seance/phase resource-add actions - $attach wires the new resource to
    // whichever of the three the caller is actually adding to (exactly one gets set, matching
    // LibraryResource's XOR shape - see its class docblock).
    private function handleResourceForm(Request $request, EntityManagerInterface $entityManager, FileUploadService $fileUploadService, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository, \Closure $attach): void
    {
        $form = $this->createForm(LibraryResourceType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return;
        }

        /** @var UploadedFile|null $file */
        $file = $form->get('file')->getData();
        $url = $form->get('url')->getData();

        if ((null === $file) === (null === $url)) {
            // Either both empty or both filled - exactly one source is expected.
            $this->addFlash('error', null === $file ? 'libraryResourceMissingSourceFlashMessage' : 'libraryResourceBothSourcesFlashMessage');

            return;
        }

        $resource = new LibraryResource($this->currentUser(), (string) $form->get('label')->getData());
        $attach($resource);

        $teacher = $this->currentUser();
        foreach ($tagResolver->resolveMany($blocTagRepository, LibraryBlocTag::class, $teacher, $request->request->all('blocs')) as $bloc) {
            $resource->addBloc($bloc);
        }
        $resource->setNiveau($tagResolver->resolveOne($niveauTagRepository, LibraryNiveauTag::class, $teacher, $request->request->get('niveau')));
        $resource->setOption($tagResolver->resolveOne($optionTagRepository, LibraryOptionTag::class, $teacher, $request->request->get('option')));

        if (null !== $file) {
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
            $key = $fileUploadService->upload(self::RESOURCE_UPLOAD_PREFIX, sprintf('%s.%s', bin2hex(random_bytes(8)), $extension), $file);
            $resource->setType(LibraryResourceSourceType::Upload);
            $resource->setStorageKey($key);
        } else {
            $resource->setType(LibraryResourceSourceType::Link);
            $resource->setUrl($url);
        }

        $entityManager->persist($resource);
        $entityManager->flush();

        $this->addFlash('success', 'libraryResourceAddedFlashMessage');
    }

    private function deleteResource(LibraryResource $resource, Request $request, EntityManagerInterface $entityManager, FileUploadService $fileUploadService): void
    {
        if (!$this->isCsrfTokenValid('library_resource_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (LibraryResourceSourceType::Upload === $resource->getType() && null !== $resource->getStorageKey()) {
            $fileUploadService->delete($resource->getStorageKey());
        }

        $entityManager->remove($resource);
        $entityManager->flush();

        $this->addFlash('success', 'libraryResourceRemovedFlashMessage');
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

    // Resolves the raw niveau/option/blocs[] request fields (see App\Form\SequenceTemplateType's
    // docblock for why they aren't real form fields) into this teacher's tags, creating any that
    // don't exist yet. Replaces the blocs collection wholesale rather than diffing, since there's
    // no Symfony Form CollectionType handling the add/remove for us here.
    private function applyTags(SequenceTemplate $sequenceTemplate, Request $request, LibraryTagResolver $tagResolver, LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): void
    {
        $teacher = $this->currentUser();

        $sequenceTemplate->setNiveau($tagResolver->resolveOne($niveauTagRepository, LibraryNiveauTag::class, $teacher, $request->request->get('niveau')));
        $sequenceTemplate->setOption($tagResolver->resolveOne($optionTagRepository, LibraryOptionTag::class, $teacher, $request->request->get('option')));

        foreach ($sequenceTemplate->getBlocs()->toArray() as $bloc) {
            $sequenceTemplate->removeBloc($bloc);
        }
        foreach ($tagResolver->resolveMany($blocTagRepository, LibraryBlocTag::class, $teacher, $request->request->all('blocs')) as $bloc) {
            $sequenceTemplate->addBloc($bloc);
        }
    }

    // Feeds the niveau/option/blocs <select> preload options (Tom Select create-or-reuse) on the
    // sequence form and every resource-attach form - always this teacher's own tags only.
    /** @return array{niveau: list<LibraryNiveauTag>, option: list<LibraryOptionTag>, blocs: list<LibraryBlocTag>} */
    private function libraryTagOptions(LibraryNiveauTagRepository $niveauTagRepository, LibraryOptionTagRepository $optionTagRepository, LibraryBlocTagRepository $blocTagRepository): array
    {
        $teacher = $this->currentUser();

        return [
            'niveau' => $niveauTagRepository->findAllForTeacher($teacher),
            'option' => $optionTagRepository->findAllForTeacher($teacher),
            'blocs' => $blocTagRepository->findAllForTeacher($teacher),
        ];
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
