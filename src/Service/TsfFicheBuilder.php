<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipFormationCenter;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;

/**
 * Flattens a program's competency referential into the list of fiches the TSF prints - one per
 * competency, in referential order, each carrying everything its page needs.
 *
 * A view model rather than entities handed to the template: the fiche's header lines are composed
 * from three different places (the option, the block's code, the certification that option
 * prepares), and composing them in Twig would put that rule in a template nobody tests.
 *
 * @phpstan-type Fiche array{
 *     code: string,
 *     label: string,
 *     unit: string,
 *     certification: string,
 *     volumeHours: string,
 *     teachingPeriod: string,
 *     teacher: string,
 *     occupationDescription: string,
 *     knowledgeHtml: string,
 *     activitiesHtml: string,
 *     performanceCriteriaHtml: string,
 *     diagnostic: string,
 *     summative: string,
 *     certifying: string
 * }
 */
class TsfFicheBuilder
{
    public function __construct(
        private readonly SkillGroupRepository $skillGroups,
        private readonly SkillRepository $skills,
        private readonly ProgramCertificationRepository $certifications,
        private readonly InternshipFormationCenterRepository $formationCenters,
    ) {
    }

    /** @return list<Fiche> */
    public function build(Program $program): array
    {
        $fiches = [];

        foreach ($this->skillGroups->findAllOrderedForProgram($program) as $group) {
            $unit = $this->unitLine($group);
            $certification = $this->certificationLine($program, $group);

            foreach ($this->skills->findAllOrderedForSkillGroup($group) as $skill) {
                $fiches[] = [
                    'code' => $skill->getCode() ?? '',
                    'label' => $skill->getLabel(),
                    'unit' => $unit,
                    'certification' => $certification,
                    'volumeHours' => $this->hours($skill),
                    'teachingPeriod' => $skill->getTeachingPeriodLabel() ?? '',
                    'teacher' => $this->teacherName($skill),
                    'occupationDescription' => $skill->getOccupationDescription() ?? '',
                    'knowledgeHtml' => $skill->getKnowledgeHtml() ?? '',
                    'activitiesHtml' => $skill->getActivitiesHtml() ?? '',
                    'performanceCriteriaHtml' => $skill->getPerformanceCriteriaHtml() ?? '',
                    'diagnostic' => $skill->getDiagnosticAssessmentHtml() ?? '',
                    'summative' => $skill->getSummativeAssessmentHtml() ?? '',
                    'certifying' => $skill->getCertifyingAssessmentHtml() ?? '',
                ];
            }
        }

        return $fiches;
    }

    public function formationCenter(): ?InternshipFormationCenter
    {
        return $this->formationCenters->findSingleton();
    }

    /**
     * "CDA - CCP 1 : Développer une application sécurisée", dropping whichever part is missing - a
     * cross-cutting block has neither an option nor a code and prints as its label alone.
     */
    private function unitLine(SkillGroup $group): string
    {
        $prefix = [];

        $firstOption = $group->getOptions()->first();
        if (false !== $firstOption) {
            $prefix[] = $firstOption->getShortName();
        }

        $code = $group->getCode();
        if (null !== $code && '' !== trim($code)) {
            $prefix[] = trim($code);
        }

        return [] === $prefix
            ? $group->getLabel()
            : implode(' - ', $prefix).' : '.$group->getLabel();
    }

    /**
     * The title the fiche carries under the program's name: the certification the block's own
     * option prepares, falling back to the program-wide one.
     *
     * Deliberately NOT "whatever certification the program has" when a block belongs to an option:
     * the source document prints the AIS title on the CDA fiches too, which is an error of that
     * document and not something to reproduce.
     */
    private function certificationLine(Program $program, SkillGroup $group): string
    {
        $firstOption = $group->getOptions()->first();
        $certification = $this->certifications->findForOption($program, false === $firstOption ? null : $firstOption);

        return $certification instanceof ProgramCertification ? $certification->getFullLabel() : '';
    }

    /** "30" rather than "30.00" - the fiche prints whole hours, and half-hours keep their half. */
    private function hours(Skill $skill): string
    {
        $raw = $skill->getVolumeHours();
        if (null === $raw || '' === $raw) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $raw, 2, ',', ' '), '0'), ',');
    }

    private function teacherName(Skill $skill): string
    {
        $teacher = $skill->getTeacher();
        if (null === $teacher) {
            return '';
        }

        return $teacher->getDisplayName() ?? $teacher->getUsername();
    }
}
