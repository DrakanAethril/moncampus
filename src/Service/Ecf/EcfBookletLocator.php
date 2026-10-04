<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfBooklet;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use App\Repository\EcfBookletRepository;
use App\Repository\ProgramEcfSettingsRepository;

/**
 * Finds the ECF booklet an alternance shows (design/validated/ecf-booklet.md, R2): the student's
 * booklet for the titre the alternance's formation names. Two formations naming the same titre
 * code and millésime - CDA 1 and CDA 2 - lead to the same booklet.
 */
class EcfBookletLocator
{
    public function __construct(
        private readonly ProgramEcfSettingsRepository $settingsRepository,
        private readonly EcfBookletRepository $bookletRepository,
    ) {
    }

    /** The formation's settings when the booklet is switched on and its titre is named, null otherwise. */
    public function enabledSettings(?Program $program): ?ProgramEcfSettings
    {
        if (null === $program) {
            return null;
        }

        $settings = $this->settingsRepository->findOneByProgram($program);
        if (null === $settings || !$settings->isEnabled() || '' === trim((string) $settings->getTitleCode()) || '' === trim((string) $settings->getMillesime())) {
            return null;
        }

        return $settings;
    }

    public function isAvailable(InternshipTutorLink $tutorLink): bool
    {
        return null !== $tutorLink->getStudent() && null !== $this->enabledSettings($tutorLink->getProgram());
    }

    public function find(InternshipTutorLink $tutorLink): ?EcfBooklet
    {
        $settings = $this->enabledSettings($tutorLink->getProgram());
        $student = $tutorLink->getStudent();
        if (null === $settings || null === $student) {
            return null;
        }

        return $this->bookletRepository->findOneForStudentAndTitle($student, trim((string) $settings->getTitleCode()), trim((string) $settings->getMillesime()));
    }

    /**
     * The booklet, or a new one nobody has persisted - reading an empty booklet writes nothing; the
     * first save does. Null when the alternance's formation does not keep the booklet.
     */
    public function findOrNew(InternshipTutorLink $tutorLink): ?EcfBooklet
    {
        $settings = $this->enabledSettings($tutorLink->getProgram());
        $student = $tutorLink->getStudent();
        if (null === $settings || null === $student) {
            return null;
        }

        return $this->find($tutorLink) ?? new EcfBooklet($student, trim((string) $settings->getTitleCode()), trim((string) $settings->getMillesime()));
    }
}
