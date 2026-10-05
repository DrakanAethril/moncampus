<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\InternshipTutorLink;
use App\Enum\EcfPart;

/**
 * Builds the EcfOverview of an alternance: the activity-types in order with their state, the groups
 * left out for lack of an option, the activities whose group is gone, and whether the synthesis is
 * open yet (R9: once every activity has a signed part).
 */
class EcfBookletOverview
{
    public function __construct(
        private readonly EcfBookletLocator $locator,
        private readonly EcfActivityTypes $activityTypes,
        private readonly EcfMastery $mastery,
    ) {
    }

    public function build(InternshipTutorLink $tutorLink): ?EcfOverview
    {
        $settings = $this->locator->enabledSettings($tutorLink->getProgram());
        $title = $this->locator->title($tutorLink);
        $booklet = $this->locator->findOrNew($tutorLink);
        if (null === $settings || null === $title || null === $booklet) {
            return null;
        }

        $types = $this->activityTypes->forTutorLink($tutorLink);
        $rows = [];
        $allDecided = [] !== $types;
        foreach ($types as $type) {
            $activity = $booklet->activityFor($type->code);
            $state = $this->mastery->state($booklet, $activity);
            $lastVisa = null;
            if (null !== $activity) {
                $visas = [...EcfMastery::visasOf($booklet, $activity, EcfPart::Main), ...EcfMastery::visasOf($booklet, $activity, EcfPart::Complementary)];
                usort($visas, static fn ($a, $b): int => $b->getSignedAt() <=> $a->getSignedAt());
                $lastVisa = $visas[0] ?? null;
            }
            $allDecided = $allDecided && null !== $activity && null !== $this->mastery->decisiveResult($booklet, $activity);
            $rows[] = ['type' => $type, 'activity' => $activity, 'state' => $state, 'lastVisa' => $lastVisa];
        }

        return new EcfOverview(
            $booklet,
            $settings,
            $title,
            $rows,
            $this->activityTypes->excludedForOptions($tutorLink),
            $this->activityTypes->orphans($booklet, $types),
            $allDecided,
            EcfMastery::visasOf($booklet, null, EcfPart::Synthesis),
        );
    }
}
