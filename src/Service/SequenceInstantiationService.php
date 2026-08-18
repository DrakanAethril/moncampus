<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LibraryResource;
use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceInstance;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\LibraryResourceSourceType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deep-copies template-layer content (SequenceTemplate/SeanceTemplate/SeancePhaseTemplate, plus
 * any attached LibraryResource) into frozen instance-layer rows for a specific Program - see
 * design/validated/teaching-sequence-library.md's "two-layer model". Neither method schedules any
 * SeanceInstance against a real date/LessonSession - that's a separate step
 * (App\Controller\ProgramSequenceInstanceController::schedule()), so a fresh instance always
 * starts unscheduled.
 */
class SequenceInstantiationService
{
    private const string RESOURCE_UPLOAD_PREFIX = 'library-resource-instances/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function instantiateSequence(SequenceTemplate $template, Program $program, User $createdBy): SequenceInstance
    {
        $sequenceInstance = new SequenceInstance($program, $createdBy);
        $sequenceInstance->setSourceTemplate($template);
        $sequenceInstance->setTitre($template->getTitre());
        $sequenceInstance->setCapacitesAttendues($template->getCapacitesAttendues());
        $sequenceInstance->setPreRequis($template->getPreRequis());
        $sequenceInstance->setObjectifs($template->getObjectifs());
        $sequenceInstance->setTransversalites($template->getTransversalites());
        $sequenceInstance->setSituationProblematique($template->getSituationProblematique());
        $sequenceInstance->setSupportsGeneraux($template->getSupportsGeneraux());
        $sequenceInstance->setDifferentiation($template->getDifferentiation());
        $sequenceInstance->setWatchPoints($template->getWatchPoints());

        $this->entityManager->persist($sequenceInstance);

        $this->duplicateLibraryResources(
            $template->getLibraryResources(),
            static fn (LibraryResourceInstance $copy): mixed => $copy->setSequenceInstance($sequenceInstance),
        );

        foreach ($template->getSeanceTemplates() as $seanceTemplate) {
            $seanceInstance = $this->buildSeanceInstance($seanceTemplate, $program, $createdBy);
            $seanceInstance->setSequenceInstance($sequenceInstance);
            $this->entityManager->persist($seanceInstance);
        }

        $this->entityManager->flush();

        return $sequenceInstance;
    }

    /**
     * The same frozen copy, taken from **another class's instance** rather than from a library
     * template - the one branch the progression trame needs when the séquence's template has been
     * deleted since (design/validated/content-sharing-between-teachers.md).
     *
     * A SequenceInstance holds its own copy of every text field, so the class-level content survives
     * its template's deletion; what is lost is only the *library* origin, and the copy says so by
     * carrying no source template at all. Its resources are duplicated exactly as instantiateSequence()
     * duplicates a template's: a real second S3 object each, never a pointer at the author's.
     */
    public function instantiateFromInstance(SequenceInstance $source, Program $program, User $createdBy): SequenceInstance
    {
        $copy = new SequenceInstance($program, $createdBy);
        $copy->setTitre($source->getTitre());
        $copy->setCapacitesAttendues($source->getCapacitesAttendues());
        $copy->setPreRequis($source->getPreRequis());
        $copy->setObjectifs($source->getObjectifs());
        $copy->setTransversalites($source->getTransversalites());
        $copy->setSituationProblematique($source->getSituationProblematique());
        $copy->setSupportsGeneraux($source->getSupportsGeneraux());
        $copy->setDifferentiation($source->getDifferentiation());
        $copy->setWatchPoints($source->getWatchPoints());

        $this->entityManager->persist($copy);

        $this->duplicateResourceInstances(
            $source->getLibraryResourceInstances(),
            static fn (LibraryResourceInstance $duplicate): mixed => $duplicate->setSequenceInstance($copy),
        );

        foreach ($source->getSeanceInstances() as $seanceInstance) {
            $seanceCopy = new SeanceInstance($program, $createdBy);
            $seanceCopy->setSourceTemplate($seanceInstance->getSourceTemplate());
            $seanceCopy->setOrdre($seanceInstance->getOrdre());
            $seanceCopy->setTitre($seanceInstance->getTitre());
            $seanceCopy->setDuree($seanceInstance->getDuree());
            $seanceCopy->setEvaluationNature($seanceInstance->getEvaluationNature());
            $seanceCopy->setObjectifs($seanceInstance->getObjectifs());
            $seanceCopy->setAvantDescription($seanceInstance->getAvantDescription());
            $seanceCopy->setApresDescription($seanceInstance->getApresDescription());
            $seanceCopy->setMaterials($seanceInstance->getMaterials());
            $seanceCopy->setWatchPoints($seanceInstance->getWatchPoints());
            $seanceCopy->setCahierDeTexteDescription($seanceInstance->getCahierDeTexteDescription());
            $seanceCopy->setSequenceInstance($copy);
            $this->entityManager->persist($seanceCopy);

            $this->duplicateResourceInstances(
                $seanceInstance->getLibraryResourceInstances(),
                static fn (LibraryResourceInstance $duplicate): mixed => $duplicate->setSeanceInstance($seanceCopy),
            );

            foreach ($seanceInstance->getSeancePhaseInstances() as $phaseInstance) {
                $phaseCopy = new SeancePhaseInstance($seanceCopy);
                $phaseCopy->setOrdre($phaseInstance->getOrdre());
                $phaseCopy->setNom($phaseInstance->getNom());
                $phaseCopy->setDuree($phaseInstance->getDuree());
                $phaseCopy->setContenu($phaseInstance->getContenu());
                $phaseCopy->setObjectifs($phaseInstance->getObjectifs());
                $phaseCopy->setEnseignant($phaseInstance->getEnseignant());
                $phaseCopy->setEtudiant($phaseInstance->getEtudiant());
                $phaseCopy->setMoyensSupports($phaseInstance->getMoyensSupports());
                $phaseCopy->setDifficultes($phaseInstance->getDifficultes());
                $this->entityManager->persist($phaseCopy);

                $this->duplicateResourceInstances(
                    $phaseInstance->getLibraryResourceInstances(),
                    static fn (LibraryResourceInstance $duplicate): mixed => $duplicate->setSeancePhaseInstance($phaseCopy),
                );
            }
        }

        $this->entityManager->flush();

        return $copy;
    }

    /**
     * The instance-layer twin of duplicateLibraryResources(): an Upload gets a real second object of
     * its own, a Link is a string.
     *
     * @param iterable<LibraryResourceInstance>                $sourceResources
     * @param \Closure(LibraryResourceInstance): mixed         $attach
     */
    private function duplicateResourceInstances(iterable $sourceResources, \Closure $attach): void
    {
        foreach ($sourceResources as $resource) {
            $duplicate = new LibraryResourceInstance((string) $resource->getLabel());
            $duplicate->setType($resource->getType());

            if (LibraryResourceSourceType::Upload === $resource->getType()) {
                $sourceKey = (string) $resource->getStorageKey();
                $extension = pathinfo($sourceKey, \PATHINFO_EXTENSION);
                $newKey = self::RESOURCE_UPLOAD_PREFIX.\sprintf('%d-%s%s', $resource->getId(), bin2hex(random_bytes(4)), '' !== $extension ? '.'.$extension : '');
                $this->fileUploadService->copy($sourceKey, $newKey);
                $duplicate->setStorageKey($newKey);
            } else {
                $duplicate->setUrl($resource->getUrl());
            }

            $attach($duplicate);
            $this->entityManager->persist($duplicate);
        }
    }

    private function buildSeanceInstance(SeanceTemplate $template, Program $program, User $createdBy): SeanceInstance
    {
        $seanceInstance = new SeanceInstance($program, $createdBy);
        $seanceInstance->setSourceTemplate($template);
        $seanceInstance->setOrdre($template->getOrdre());
        $seanceInstance->setTitre($template->getTitre());
        $seanceInstance->setDuree($template->getDuree());
        $seanceInstance->setEvaluationNature($template->getEvaluationNature());
        $seanceInstance->setObjectifs($template->getObjectifs());
        $seanceInstance->setAvantDescription($template->getAvantDescription());
        $seanceInstance->setApresDescription($template->getApresDescription());
        $seanceInstance->setMaterials($template->getMaterials());
        $seanceInstance->setWatchPoints($template->getWatchPoints());
        $seanceInstance->setCahierDeTexteDescription($template->getCahierDeTexteDescription());

        $this->duplicateLibraryResources(
            $template->getLibraryResources(),
            static fn (LibraryResourceInstance $copy): mixed => $copy->setSeanceInstance($seanceInstance),
        );

        foreach ($template->getSeancePhaseTemplates() as $phaseTemplate) {
            $phaseInstance = new SeancePhaseInstance($seanceInstance);
            $phaseInstance->setOrdre($phaseTemplate->getOrdre());
            $phaseInstance->setNom($phaseTemplate->getNom());
            $phaseInstance->setDuree($phaseTemplate->getDuree());
            $phaseInstance->setContenu($phaseTemplate->getContenu());
            $phaseInstance->setObjectifs($phaseTemplate->getObjectifs());
            $phaseInstance->setEnseignant($phaseTemplate->getEnseignant());
            $phaseInstance->setEtudiant($phaseTemplate->getEtudiant());
            $phaseInstance->setMoyensSupports($phaseTemplate->getMoyensSupports());
            $phaseInstance->setDifficultes($phaseTemplate->getDifficultes());
            $this->entityManager->persist($phaseInstance);

            $this->duplicateLibraryResources(
                $phaseTemplate->getLibraryResources(),
                static fn (LibraryResourceInstance $copy): mixed => $copy->setSeancePhaseInstance($phaseInstance),
            );
        }

        return $seanceInstance;
    }

    // "Duplicated, not referenced" (see the class docblock and the design doc): an Upload-type
    // resource gets a real second S3 object of its own via FileUploadService::copy(), not a
    // pointer back at the library original - deleting or replacing that original afterward can
    // never change what an already-instantiated Program's data shows. A Link-type resource is
    // just a URL string, so "duplicating" it is simply copying the value.
    /**
     * @param iterable<LibraryResource>                 $sourceResources
     * @param \Closure(LibraryResourceInstance): mixed $attach attaches the copy to its new parent instance
     */
    private function duplicateLibraryResources(iterable $sourceResources, \Closure $attach): void
    {
        foreach ($sourceResources as $resource) {
            $copy = new LibraryResourceInstance((string) $resource->getLabel());
            $copy->setType($resource->getType());

            if (LibraryResourceSourceType::Upload === $resource->getType()) {
                $sourceKey = (string) $resource->getStorageKey();
                $extension = pathinfo($sourceKey, PATHINFO_EXTENSION);
                $newKey = self::RESOURCE_UPLOAD_PREFIX.sprintf('%d-%s%s', $resource->getId(), bin2hex(random_bytes(4)), '' !== $extension ? '.'.$extension : '');
                $this->fileUploadService->copy($sourceKey, $newKey);
                $copy->setStorageKey($newKey);
            } else {
                $copy->setUrl($resource->getUrl());
            }

            $attach($copy);
            $this->entityManager->persist($copy);
        }
    }
}
