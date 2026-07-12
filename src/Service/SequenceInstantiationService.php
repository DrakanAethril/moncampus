<?php

namespace App\Service;

use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceInstance;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deep-copies template-layer content (SequenceTemplate/SeanceTemplate/SeancePhaseTemplate) into
 * frozen instance-layer rows for a specific Program - see
 * design/validated/teaching-sequence-library.md's "two-layer model". Neither method schedules any
 * SeanceInstance against a real date/LessonSession - that's a separate step
 * (App\Controller\ProgramSequenceInstanceController::schedule()), so a fresh instance always
 * starts unscheduled.
 */
class SequenceInstantiationService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
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
        }

        return $seanceInstance;
    }
}
