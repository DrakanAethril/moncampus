<?php

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

    // The standalone "gap-filling" case - a single séance pulled from the library, detached from
    // any séquence.
    public function instantiateStandaloneSeance(SeanceTemplate $template, Program $program, User $createdBy): SeanceInstance
    {
        $seanceInstance = $this->buildSeanceInstance($template, $program, $createdBy);

        $this->entityManager->persist($seanceInstance);
        $this->entityManager->flush();

        return $seanceInstance;
    }

    private function buildSeanceInstance(SeanceTemplate $template, Program $program, User $createdBy): SeanceInstance
    {
        $seanceInstance = new SeanceInstance($program, $createdBy);
        $seanceInstance->setSourceTemplate($template);
        $seanceInstance->setOrdre($template->getOrdre());
        $seanceInstance->setTitre($template->getTitre());
        $seanceInstance->setDuree($template->getDuree());
        $seanceInstance->setObjectifs($template->getObjectifs());
        $seanceInstance->setAvantDescription($template->getAvantDescription());
        $seanceInstance->setApresDescription($template->getApresDescription());

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
