<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioEvidence;
use App\Entity\PortfolioShowcase;

/**
 * A piece of a portfolio as plain data - what a decision records in PortfolioReview::$snapshot,
 * what tells whether a save changed anything, and what the review screen compares to name
 * « ce qui a changé depuis la version validée ».
 *
 * Only what the student writes is in it: states, revisions and dates of decision are not the
 * student's text and do not make a change.
 */
final class PortfolioSnapshot
{
    /** @return array<string, mixed> */
    public static function ofAchievement(PortfolioAchievement $achievement): array
    {
        $claims = [];
        foreach ($achievement->getClaims() as $claim) {
            $claims[(string) $claim->getCompetency()?->getId()] = $claim->getJustification();
        }
        ksort($claims);

        return [
            'title' => $achievement->getTitle(),
            'setting' => $achievement->getSetting()->value,
            'startsOn' => $achievement->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $achievement->getEndsOn()?->format('Y-m-d'),
            'organisation' => $achievement->getOrganisation(),
            'place' => $achievement->getPlace(),
            'teamwork' => $achievement->isTeamwork(),
            'teamNote' => $achievement->getTeamNote(),
            'description' => $achievement->getDescriptionHtml(),
            'claims' => $claims,
            'evidences' => self::evidences($achievement->getEvidences()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    public static function ofShowcase(PortfolioShowcase $showcase): array
    {
        return [
            'achievementId' => $showcase->getAchievement()?->getId(),
            'achievementRevision' => $showcase->getAchievementRevision(),
            'conditions' => $showcase->getConditionsHtml(),
            'resources' => $showcase->getResourcesHtml(),
            'access' => $showcase->getAccessHtml(),
            'description' => $showcase->getDescriptionHtml(),
            'evidences' => self::evidences($showcase->getEvidences()->toArray()),
        ];
    }

    /**
     * The keys of a snapshot that differ from another - for the review screen's banner.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /**
     * @param array<array-key, PortfolioEvidence> $evidences
     *
     * @return list<array{kind: string, label: string, url: ?string, file: ?string, submission: ?int}>
     */
    private static function evidences(array $evidences): array
    {
        return array_values(array_map(static fn (PortfolioEvidence $evidence): array => [
            'kind' => $evidence->getKind()->value,
            'label' => $evidence->getLabel(),
            'url' => $evidence->getUrl(),
            'file' => $evidence->getFileKey(),
            'submission' => $evidence->getSubmission()?->getId(),
        ], $evidences));
    }
}
