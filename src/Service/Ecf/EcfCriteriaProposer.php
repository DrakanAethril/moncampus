<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\Skill;

/**
 * « Proposer depuis le référentiel »: one evaluation line per competence of the activity-type,
 * described by the competence's performance criteria - which is, word for word, what the ministry's
 * example booklets describe. The screen only puts a proposal into a line left empty; nothing is
 * saved until the administrator saves.
 */
class EcfCriteriaProposer
{
    /**
     * @return list<array{competence: int, description: string}>
     */
    public function proposalsFor(EcfActivityType $type): array
    {
        $skills = array_values(array_filter(
            $type->group->getSkills()->toArray(),
            static fn (Skill $skill): bool => null === $skill->getInactiveDate(),
        ));
        usort($skills, static fn (Skill $a, Skill $b): int => [$a->getOrder(), $a->getId()] <=> [$b->getOrder(), $b->getId()]);

        $proposals = [];
        foreach ($skills as $index => $skill) {
            $lines = self::lines((string) $skill->getPerformanceCriteriaHtml());
            $proposals[] = [
                'competence' => $index + 1,
                'description' => implode("\n", [] !== $lines ? $lines : [$skill->getLabel()]),
            ];
        }

        return $proposals;
    }

    /**
     * The visible lines of a rich-text field: one per list item or paragraph, tags dropped.
     *
     * @return list<string>
     */
    public static function lines(string $html): array
    {
        $text = preg_replace('#<\s*(br|/p|/li|/div|/h[1-6])\b[^>]*>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''), explode("\n", $text)),
            static fn (string $line): bool => '' !== $line,
        ));
    }
}
