<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionMoment;
use App\Enum\AccessConditionType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sentence a student reads on a locked row: "Disponible une fois le TP 3 déposé", never "accès
 * refusé". This is what makes the feature usable rather than merely correct - a lock with no way
 * out is indistinguishable from a bug.
 *
 * Two rules are enforced here rather than left to callers:
 *
 * - a sentence says the consequence and the gesture that opens it, so the wording differs between a
 *   whole listening and a partial one, and between the two ends of a séance's slot;
 * - a sentence never names an object this reader may not know about - AccessConditionNames comes
 *   back short in that case and the generic takes over.
 */
class AccessConditionLabeler
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param list<AccessConditionLeaf> $leaves
     *
     * @return list<string>
     */
    public function reasons(array $leaves, AccessConditionNames $names, StudentAccessFacts $facts): array
    {
        $reasons = [];
        foreach ($leaves as $leaf) {
            $reasons[] = $this->reason($leaf, $names, $facts);
        }

        // Two leaves can perfectly well produce one sentence - the same generic twice, most often.
        // Printing it twice would read as two different things to do.
        return array_values(array_unique($reasons));
    }

    public function reason(AccessConditionLeaf $leaf, AccessConditionNames $names, StudentAccessFacts $facts): string
    {
        $name = $names->nameOf($leaf->type, $leaf->targetId) ?? $this->translator->trans('accessConditionGenericTarget');

        return match ($leaf->type) {
            AccessConditionType::QuizScore => $this->scoreReason($leaf, $name),
            AccessConditionType::AssignmentDone => $this->translator->trans('accessConditionReasonAssignmentDone', ['%name%' => $name]),
            AccessConditionType::AudioListened => $this->percentReason($leaf, $name, 'accessConditionReasonAudioListenedWhole', 'accessConditionReasonAudioListenedPartial'),
            AccessConditionType::VideoWatched => $this->percentReason($leaf, $name, 'accessConditionReasonVideoWatchedWhole', 'accessConditionReasonVideoWatchedPartial'),
            AccessConditionType::ResourceViewed => $this->translator->trans('accessConditionReasonResourceViewed', ['%name%' => $name]),
            AccessConditionType::SeancePassed => $this->seanceReason($leaf, $name, $facts),
            AccessConditionType::DateFrom => $this->translator->trans('accessConditionReasonDateFrom', ['%date%' => $this->formatDate($leaf->at)]),
            AccessConditionType::Group => $this->translator->trans('accessConditionReasonGroup', ['%name%' => $name]),
        };
    }

    private function scoreReason(AccessConditionLeaf $leaf, string $name): string
    {
        if (null !== $leaf->minPercent && null !== $leaf->maxPercent) {
            return $this->translator->trans('accessConditionReasonQuizScoreRange', [
                '%name%' => $name,
                '%min%' => $leaf->minPercent,
                '%max%' => $leaf->maxPercent,
            ]);
        }

        if (null !== $leaf->maxPercent) {
            return $this->translator->trans('accessConditionReasonQuizScoreMax', ['%name%' => $name, '%percent%' => $leaf->maxPercent]);
        }

        return $this->translator->trans('accessConditionReasonQuizScoreMin', ['%name%' => $name, '%percent%' => $leaf->minPercent ?? 100]);
    }

    private function percentReason(AccessConditionLeaf $leaf, string $name, string $wholeKey, string $partialKey): string
    {
        $percent = $leaf->requiredPercent();

        return 100 <= $percent
            ? $this->translator->trans($wholeKey, ['%name%' => $name])
            : $this->translator->trans($partialKey, ['%name%' => $name, '%percent%' => $percent]);
    }

    /**
     * The one sentence that must not promise what it cannot keep: a séance with no slot has no
     * date, and "Disponible après la séance 4" would have a student waiting for one.
     */
    private function seanceReason(AccessConditionLeaf $leaf, string $name, StudentAccessFacts $facts): string
    {
        $at = null === $leaf->targetId ? null : $facts->seanceMoment($leaf->targetId, $leaf->moment);

        if (null === $at) {
            return $this->translator->trans('accessConditionReasonSeanceNotScheduled', ['%name%' => $name]);
        }

        $key = AccessConditionMoment::Start === $leaf->moment
            ? 'accessConditionReasonSeanceStart'
            : 'accessConditionReasonSeanceEnd';

        return $this->translator->trans($key, ['%name%' => $name, '%date%' => $this->formatDate($at)]);
    }

    private function formatDate(?\DateTimeImmutable $at): string
    {
        return $at?->format('d/m/Y H:i') ?? '';
    }
}
