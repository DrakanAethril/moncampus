<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Enum\EcoCheckpointType;

/**
 * What the live screen tells the teacher about flags missing from the terrain: each regular flag
 * of the race that a runner reported (« Balise introuvable ») or that the teacher neutralised, with
 * who reported it and when - the facts a neutralisation is decided on. Read from the course's
 * runners, so it costs no query the live screen does not already make.
 */
final class EcoCheckpointReports
{
    /**
     * @return list<array{checkpoint: EcoCheckpoint, name: string, neutralized: bool, reporters: list<string>, firstReportedAt: ?\DateTimeImmutable}>
     */
    public function forCourse(EcoCourse $course): array
    {
        /** @var array<int, list<array{pseudo: string, at: \DateTimeImmutable}>> $byCheckpoint */
        $byCheckpoint = [];
        foreach ($course->getRunners() as $runner) {
            foreach ($runner->getCheckpointReports() as $report) {
                $byCheckpoint[(int) $report->getCheckpoint()->getId()][] = ['pseudo' => $runner->getPseudo() ?? '', 'at' => $report->getReportedAt()];
            }
        }

        $rows = [];
        foreach ($course->getRaceCheckpoints() as $checkpoint) {
            $reports = $byCheckpoint[(int) $checkpoint->getId()] ?? [];
            $neutralized = $course->isNeutralized($checkpoint);
            if ([] === $reports && !$neutralized) {
                continue;
            }

            usort($reports, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

            $rows[] = [
                'checkpoint' => $checkpoint,
                'name' => $checkpoint->getName() ?? '',
                'neutralized' => $neutralized,
                'reporters' => array_map(static fn (array $report): string => $report['pseudo'], $reports),
                'firstReportedAt' => $reports[0]['at'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The regular flags the teacher may still neutralise without waiting for a report: a flag
     * known gone before anyone reaches it.
     *
     * @return list<EcoCheckpoint>
     */
    public function neutralizable(EcoCourse $course): array
    {
        return array_values(array_filter(
            $course->getRaceCheckpoints(),
            static fn (EcoCheckpoint $checkpoint): bool => EcoCheckpointType::Checkpoint === $checkpoint->getType() && !$course->isNeutralized($checkpoint),
        ));
    }

    /**
     * Changes whenever a report arrives or a flag is neutralised or restored: the live screen polls
     * it and asks for the block again only then.
     */
    public function signature(EcoCourse $course): string
    {
        $parts = [];
        foreach ($course->getRunners() as $runner) {
            foreach ($runner->getCheckpointReports() as $report) {
                $parts[] = 'r'.$report->getId();
            }
        }
        foreach ($course->getRaceCheckpoints() as $checkpoint) {
            if ($course->isNeutralized($checkpoint)) {
                $parts[] = 'n'.$checkpoint->getId();
            }
        }
        sort($parts);

        return md5(implode(',', $parts));
    }
}
