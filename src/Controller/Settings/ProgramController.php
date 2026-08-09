<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Cohort;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramPeriodGroup;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Form\ProgramType;
use App\Repository\ProgramRepository;
use App\Service\FileUploadService;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Pédagogique, onglet « Formations ». À ne pas confondre avec App\Controller\ProgramController, qui sert les écrans d'une formation ; celui-ci n'en gère que la fiche de paramétrage.
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramController extends AbstractController
{
    use SettingsTabTrait;

    // App\Service\FileUploadService namespace prefixes for Program's two optional PDF uploads.
    private const string PROGRAM_SYLLABUS_FILE_PREFIX = 'programs/syllabus/';

    private const string PROGRAM_ALTERNANCE_CALENDAR_FILE_PREFIX = 'programs/alternance-calendar/';

    #[Route(path: '/settings/structure/programs', name: 'app_settings_structure_programs')]
    public function programsTab(): Response
    {
        return $this->renderTab('programs');
    }

    #[Route(path: '/settings/structure/programs/new', name: 'app_settings_structure_programs_new')]
    #[Route(path: '/settings/structure/programs/{id}/edit', name: 'app_settings_structure_programs_edit')]
    public function programForm(Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, FileUploadService $fileUploadService, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        // A real Program backs the "new" form too, not null - ProgramType's management-enabled
        // checkboxes are ordinary mapped fields that read their initial view state straight off
        // the model, so only a real instance (picking up the `= true` property defaults) renders
        // them pre-checked. Cohort/SchoolYear are nulled back out right after construction (the
        // constructor requires *some* instance) so the EntityType fields still render their
        // normal "nothing selected" placeholder - a non-persisted entity as a field's current
        // value trips EntityType's "must be managed" check.
        $program = $isEdit
            ? $this->findOrNotFound($repository, $id)
            : (new Program('', '', new Cohort('', new Track('', new Section(''))), new SchoolYear(new \DateTimeImmutable(), new \DateTimeImmutable())))
                ->setCohort(null)
                ->setSchoolYear(null);

        $form = $this->createForm(ProgramType::class, $program);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->uploadProgramFile($form, $entityManager, $fileUploadService, 'syllabusFile', self::PROGRAM_SYLLABUS_FILE_PREFIX, $entity, $entity->getSyllabusFileKey(), $entity->setSyllabusFileKey(...));
            $this->uploadProgramFile($form, $entityManager, $fileUploadService, 'alternanceCalendarFile', self::PROGRAM_ALTERNANCE_CALENDAR_FILE_PREFIX, $entity, $entity->getAlternanceCalendarFileKey(), $entity->setAlternanceCalendarFileKey(...));

            $this->addFlash('success', $isEdit ? 'programUpdatedFlashMessage' : 'programCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_programs');
        }

        return $this->render('settings/program_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/programs/{id}/deactivate', name: 'app_settings_structure_programs_deactivate', methods: ['POST'])]
    public function deactivateProgram(Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, int $id): JsonResponse
    {
        $program = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $program->setInactiveDate(new \DateTimeImmutable());
        $program->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/programs/data', name: 'app_settings_structure_programs_data')]
    public function programsData(Request $request, ProgramRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);
        $repository->hydrateOptionsAndModalities($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Program $program): array => [
                    'id' => $program->getId(),
                    'isInactive' => null !== $program->getInactiveDate(),
                    'name' => $program->getDisplayName(),
                    'shortName' => $program->getDisplayShortName(),
                    'cohortName' => $program->getCohort()->getName(),
                    'schoolYearLabel' => sprintf('%s - %s', $program->getSchoolYear()->getStartDate()->format('Y'), $program->getSchoolYear()->getEndDate()->format('Y')),
                    'periodGroupName' => $this->periodGroupNames($program),
                    'optionNames' => $this->optionNames($program->getOptions()),
                    'modalityNames' => $this->modalityNames($program->getModalities()),
                    'creationDate' => $program->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $program->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($program->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($program->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($program->getLastUpdatedBy()),
                    'lastUpdatedDate' => $program->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    /** @param Collection<int, Option> $options */
    private function optionNames(Collection $options): string
    {
        return implode(', ', array_map(fn (Option $option): string => $option->getShortName(), $options->toArray()));
    }

    /** @param Collection<int, Modality> $modalities */
    private function modalityNames(Collection $modalities): string
    {
        return implode(', ', array_map(fn (Modality $modality): string => $modality->getName(), $modalities->toArray()));
    }

    // Ordered by priority (most important first) - matches the "Groupes de périodes" tab's
    // drag-and-drop order (Program\SettingsPeriodGroupController).
    private function periodGroupNames(Program $program): string
    {
        $links = $program->getProgramPeriodGroups()->toArray();
        usort($links, static fn (ProgramPeriodGroup $a, ProgramPeriodGroup $b): int => $a->getPriority() <=> $b->getPriority());

        if ([] === $links) {
            return '—';
        }

        return implode(', ', array_map(static fn (ProgramPeriodGroup $link): string => $link->getPeriodGroup()->getName(), $links));
    }

    // Handles one of Program's two optional PDF upload fields (syllabusFile/alternanceCalendarFile,
    // both unmapped FileType fields) - same ordering as ProfileController::uploadAvatar(): the new
    // file is uploaded and its key persisted (flush) before the old S3 object is deleted, so a
    // mid-upload failure never leaves a broken reference. No-op when no file was submitted this
    // time (edit forms are re-submitted without re-selecting an already-uploaded file).
    private function uploadProgramFile(FormInterface $form, EntityManagerInterface $entityManager, FileUploadService $fileUploadService, string $fieldName, string $prefix, Program $program, ?string $oldKey, \Closure $setNewKey): void
    {
        $file = $form->get($fieldName)->getData();

        if (!$file instanceof UploadedFile) {
            return;
        }

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $newKey = $fileUploadService->upload($prefix, sprintf('%d-%d.%s', $program->getId(), time(), $extension), $file);

        $setNewKey($newKey);
        $entityManager->flush();

        if (null !== $oldKey) {
            $fileUploadService->delete($oldKey);
        }
    }
}
