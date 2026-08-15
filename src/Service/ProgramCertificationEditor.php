<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Form\ProgramCertificationType;
use App\Repository\ProgramCertificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The certification zone of the « Dénomination » screen, shared by the two controllers that render
 * it: Program\InternshipDenominationController (Formation > Livret de l'alternant) and
 * UfaController::formationDenomination() (UFA > Formations). Both shells include the same
 * templates/program/internship/_denomination_content.html.twig, so both have to feed it the same
 * rows - a duplicated private helper is what let one of them ship broken.
 *
 * @phpstan-type CertificationRow array{fieldName: string, option: Option|null, certification: ProgramCertification}
 */
class ProgramCertificationEditor
{
    public function __construct(
        private readonly ProgramCertificationRepository $certifications,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * One row per certifying option, or a single null-option row when the program has none - see
     * ProgramCertification's docblock.
     *
     * @return list<CertificationRow>
     */
    public function rows(Program $program): array
    {
        /** @var list<Option|null> $options */
        $options = array_values($program->getOptions()->toArray());
        if ([] === $options) {
            $options = [null];
        }

        $rows = [];
        foreach ($options as $index => $option) {
            $existing = $this->certifications->findOneForProgramAndOption($program, $option);

            $rows[] = [
                // Keyed by position, not by option id: the id is null for the program-wide row, and
                // the field name only has to be stable within one rendering of this form.
                'fieldName' => 'certification_'.$index,
                'option' => $option,
                'certification' => $existing ?? new ProgramCertification($program, $option, ''),
            ];
        }

        return $rows;
    }

    /**
     * Hangs the rows off the tab's own form, so they ride its single submit button and its one
     * actionbar. Unmapped, because that form's model is InternshipProgramInfo and each of these
     * children owns a ProgramCertification of its own; the child still maps submitted values into
     * that object, only the parent's read/write of it is switched off.
     *
     * @param list<CertificationRow> $rows
     */
    public function addToForm(FormInterface $form, array $rows): void
    {
        foreach ($rows as $row) {
            $form->add($row['fieldName'], ProgramCertificationType::class, [
                'data' => $row['certification'],
                'mapped' => false,
            ]);
        }
    }

    /**
     * Presence of a row IS the setting, same as the legal names it sits under: a blank intitulé
     * deletes the row rather than storing an empty one, so an absence stays readable as an absence.
     *
     * Does not flush - the caller owns the transaction, and it has its own writes to land first.
     *
     * @param list<CertificationRow> $rows
     */
    public function save(array $rows): void
    {
        foreach ($rows as $row) {
            $certification = $row['certification'];
            $label = trim($certification->getLabel());

            if ('' === $label) {
                if (null !== $certification->getId()) {
                    $this->entityManager->remove($certification);
                }

                continue;
            }

            $certification->setLabel($label);

            if (null === $certification->getId()) {
                $this->entityManager->persist($certification);
            }
        }
    }
}
