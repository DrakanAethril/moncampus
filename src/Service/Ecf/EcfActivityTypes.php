<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Repository\SkillGroupRepository;
use App\Service\BookletSkillGroups;

/**
 * Which activity-types an ECF booklet carries (design/validated/ecf-booklet.md, R1 and R3).
 *
 * The list is the Livret de l'alternant's own: App\Service\BookletSkillGroups decides which groups
 * a student gets, options included, and this class only orders them, numbers them and refuses the
 * ones it cannot find again next year - a group with no code, or one sharing its code.
 */
class EcfActivityTypes
{
    public function __construct(
        private readonly BookletSkillGroups $bookletSkillGroups,
        private readonly SkillGroupRepository $skillGroupRepository,
    ) {
    }

    /** @return list<EcfActivityType> */
    public function forTutorLink(InternshipTutorLink $tutorLink): array
    {
        $groups = array_values(array_filter(
            $this->sorted($this->bookletSkillGroups->forTutorLink($tutorLink)),
            static fn (SkillGroup $group): bool => '' !== self::codeOf($group),
        ));

        $types = [];
        foreach ($groups as $index => $group) {
            $types[] = new EcfActivityType($index + 1, self::codeOf($group), $group->getLabel(), self::competencesOf($group), $group);
        }

        return $types;
    }

    /**
     * The booklet-visible groups of the alternance's formation the student does not get, for lack
     * of the option they are reserved to - shown struck through so nobody wonders where they went.
     *
     * @return list<SkillGroup>
     */
    public function excludedForOptions(InternshipTutorLink $tutorLink): array
    {
        $program = $tutorLink->getProgram();
        if (null === $program) {
            return [];
        }

        $kept = array_map(static fn (SkillGroup $group): ?int => $group->getId(), $this->bookletSkillGroups->forTutorLink($tutorLink));

        return array_values(array_filter(
            $this->sorted($this->skillGroupRepository->findAllActiveForProgram($program)),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet() && !\in_array($group->getId(), $kept, true),
        ));
    }

    /**
     * What the formation's « Livret ECF » tab previews: every booklet-visible group, whoever holds
     * its option.
     *
     * @return list<SkillGroup>
     */
    public function groupsOf(Program $program): array
    {
        return array_values(array_filter(
            $this->sorted($this->skillGroupRepository->findAllActiveForProgram($program)),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet(),
        ));
    }

    /**
     * Why the formation cannot turn the booklet on, as translation keys with their parameters -
     * empty when it can.
     *
     * @return list<array{key: string, params: array<string, string>}>
     */
    public function refusalsForEnabling(Program $program): array
    {
        $groups = $this->groupsOf($program);
        if ([] === $groups) {
            return [['key' => 'ecfSettingsRefusalNoGroupMessage', 'params' => []]];
        }

        $refusals = [];
        $seen = [];
        foreach ($groups as $group) {
            $code = self::codeOf($group);
            if ('' === $code) {
                $refusals[] = ['key' => 'ecfSettingsRefusalNoCodeMessage', 'params' => ['%group%' => $group->getLabel()]];

                continue;
            }

            $key = mb_strtoupper($code);
            if (isset($seen[$key])) {
                $refusals[] = ['key' => 'ecfSettingsRefusalDuplicateCodeMessage', 'params' => ['%code%' => $code, '%group%' => $group->getLabel(), '%other%' => $seen[$key]]];

                continue;
            }

            $seen[$key] = $group->getLabel();
        }

        return $refusals;
    }

    /**
     * Activities the booklet holds whose group the formation no longer offers this student. Kept and
     * never deleted (R1): they are shown apart, and printed only if a visa signed them.
     *
     * @param list<EcfActivityType> $types
     *
     * @return list<EcfActivity>
     */
    public function orphans(EcfBooklet $booklet, array $types): array
    {
        $codes = array_map(static fn (EcfActivityType $type): string => $type->code, $types);

        return array_values(array_filter(
            $booklet->getActivities()->toArray(),
            static fn (EcfActivity $activity): bool => !\in_array($activity->getGroupCode(), $codes, true),
        ));
    }

    /** @param list<EcfActivityType> $types */
    public static function find(array $types, string $code): ?EcfActivityType
    {
        foreach ($types as $type) {
            if ($type->code === $code) {
                return $type;
            }
        }

        return null;
    }

    public static function codeOf(SkillGroup $group): string
    {
        return trim((string) $group->getCode());
    }

    /** @return list<string> */
    private static function competencesOf(SkillGroup $group): array
    {
        $skills = array_values(array_filter(
            $group->getSkills()->toArray(),
            static fn (Skill $skill): bool => null === $skill->getInactiveDate(),
        ));
        usort($skills, static fn (Skill $a, Skill $b): int => [$a->getOrder(), $a->getId()] <=> [$b->getOrder(), $b->getId()]);

        return array_map(static fn (Skill $skill): string => $skill->getLabel(), $skills);
    }

    /**
     * @param list<SkillGroup> $groups
     *
     * @return list<SkillGroup>
     */
    private function sorted(array $groups): array
    {
        usort($groups, static fn (SkillGroup $a, SkillGroup $b): int => [$a->getOrder(), $a->getId()] <=> [$b->getOrder(), $b->getId()]);

        return $groups;
    }
}
