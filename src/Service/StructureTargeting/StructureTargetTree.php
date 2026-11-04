<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

use App\Entity\Program;
use App\Entity\Section;
use App\Entity\Track;

/**
 * The choices of a targeting field (App\Form\StructureTargetsType), flat for the three choice fields
 * and nested for the template: Section › Track › Program, sections and tracks by name, formations by
 * school year then short name. **Test formations are kept apart** ($testPrograms), as the navigation's
 * « Zone de test » does: a tournament opened to a real class must not take a rehearsal class along by
 * a slip of the mouse.
 */
final readonly class StructureTargetTree
{
    /**
     * @param list<Section>                                                                                       $sections
     * @param list<Track>                                                                                         $tracks
     * @param list<Program>                                                                                       $programs
     * @param list<array{section: Section, tracks: list<array{track: Track, programs: list<Program>}>}>           $nodes
     * @param list<Program>                                                                                       $testPrograms
     */
    private function __construct(
        public array $sections,
        public array $tracks,
        public array $programs,
        public array $nodes,
        public array $testPrograms,
    ) {
    }

    /**
     * @param list<Section> $sections
     * @param list<Track>   $tracks
     * @param list<Program> $programs
     */
    public static function build(array $sections, array $tracks, array $programs): self
    {
        $collator = new \Collator('fr_FR');
        usort($sections, static fn (Section $a, Section $b): int => (int) $collator->compare($a->getName(), $b->getName()));
        usort($tracks, static fn (Track $a, Track $b): int => (int) $collator->compare($a->getName(), $b->getName()));
        usort($programs, static fn (Program $a, Program $b): int => [$a->getSchoolYear()?->getStartDate(), $collator->getSortKey($a->getShortName())] <=> [$b->getSchoolYear()?->getStartDate(), $collator->getSortKey($b->getShortName())]);

        $programsByTrack = [];
        $testPrograms = [];
        foreach ($programs as $program) {
            if ($program->isTestProgram()) {
                $testPrograms[] = $program;
                continue;
            }
            $programsByTrack[(int) $program->getCohort()?->getTrack()?->getId()][] = $program;
        }

        $tracksBySection = [];
        foreach ($tracks as $track) {
            $tracksBySection[(int) $track->getSection()?->getId()][] = [
                'track' => $track,
                'programs' => $programsByTrack[(int) $track->getId()] ?? [],
            ];
        }

        $nodes = [];
        foreach ($sections as $section) {
            $nodes[] = ['section' => $section, 'tracks' => $tracksBySection[(int) $section->getId()] ?? []];
        }

        return new self($sections, $tracks, $programs, $nodes, $testPrograms);
    }
}
