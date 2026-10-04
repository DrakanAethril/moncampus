<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfBooklet;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use App\Repository\EcfBookletRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramEcfSettingsRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\InternshipLegalNames;

/**
 * Finds the ECF booklet an alternance shows (design/validated/ecf-booklet.md, R2): the student's
 * booklet for the titre their certification names - code titre and millésime, read from UFA >
 * Formations > « Dénomination » for the student's option. Two formations naming the same titre -
 * CDA 1 and CDA 2 - lead to the same booklet; a student whose certification lacks either has none.
 */
class EcfBookletLocator
{
    public function __construct(
        private readonly ProgramEcfSettingsRepository $settingsRepository,
        private readonly EcfBookletRepository $bookletRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly ProgramCertificationRepository $certificationRepository,
        private readonly InternshipLegalNames $legalNames,
    ) {
    }

    /** The formation's settings when the booklet is switched on, null otherwise. */
    public function enabledSettings(?Program $program): ?ProgramEcfSettings
    {
        if (null === $program) {
            return null;
        }

        $settings = $this->settingsRepository->findOneByProgram($program);

        return null !== $settings && $settings->isEnabled() ? $settings : null;
    }

    /** The titre this alternant's booklet prints, complete or not. */
    public function title(InternshipTutorLink $tutorLink): ?EcfTitle
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();
        if (null === $program || null === $student) {
            return null;
        }

        $options = $this->studentOptionRepository->findOptionsForStudent($program, $student);
        $option = 1 === \count($options) ? $options[0] : null;

        return EcfTitle::of(
            $this->legalNames->forStudentOptions($program, $options),
            $this->certificationRepository->findForOption($program, $option),
            $option,
            $program,
        );
    }

    public function find(InternshipTutorLink $tutorLink): ?EcfBooklet
    {
        $title = $this->availableTitle($tutorLink);
        $student = $tutorLink->getStudent();
        if (null === $title || null === $student) {
            return null;
        }

        return $this->bookletRepository->findOneForStudentAndTitle($student, $title->code, $title->millesime);
    }

    /**
     * The booklet, or a new one nobody has persisted - reading an empty booklet writes nothing; the
     * first save does. Null when the alternance's formation does not keep the booklet, or when the
     * student's certification names no code titre and millésime.
     */
    public function findOrNew(InternshipTutorLink $tutorLink): ?EcfBooklet
    {
        $title = $this->availableTitle($tutorLink);
        $student = $tutorLink->getStudent();
        if (null === $title || null === $student) {
            return null;
        }

        return $this->bookletRepository->findOneForStudentAndTitle($student, $title->code, $title->millesime)
            ?? new EcfBooklet($student, $title->code, $title->millesime);
    }

    /**
     * The certifying options of the formation - or the whole formation when it has none - whose
     * certification lacks a code titre or a millésime: their students get no booklet.
     *
     * @return list<Option|null>
     */
    public function optionsWithoutTitle(Program $program): array
    {
        /** @var list<Option|null> $options */
        $options = array_values($program->getOptions()->toArray());
        if ([] === $options) {
            $options = [null];
        }

        return array_values(array_filter($options, function (?Option $option) use ($program): bool {
            $certification = $this->certificationRepository->findForOption($program, $option);

            return !EcfTitle::of('', $certification, $option, $program)->isComplete();
        }));
    }

    private function availableTitle(InternshipTutorLink $tutorLink): ?EcfTitle
    {
        if (null === $this->enabledSettings($tutorLink->getProgram())) {
            return null;
        }

        $title = $this->title($tutorLink);

        return null !== $title && $title->isComplete() ? $title : null;
    }
}
