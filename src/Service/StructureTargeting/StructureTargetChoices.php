<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

use App\Entity\Program;
use App\Entity\Section;
use App\Entity\StructureTargetable;
use App\Entity\Track;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a targeting may name (App\Form\StructureTargetsType): the **active** structure - Sections and
 * Tracks not deactivated, Programs of an active school year whose whole chain is active - drawn as the
 * tree Section › Track › Program, test formations apart (the test zone, as in the navigation).
 *
 * What a targetable already names stays on offer even once deactivated: a form must not drop a choice
 * silently because the structure moved under it. Its parents come along so it has a place in the tree.
 */
class StructureTargetChoices
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function for(?StructureTargetable $current): StructureTargetTree
    {
        $sections = [];
        foreach ($this->entityManager->getRepository(Section::class)->findBy(['inactiveDate' => null], ['name' => 'ASC']) as $section) {
            $sections[(int) $section->getId()] = $section;
        }

        $tracks = [];
        /** @var list<Track> $activeTracks */
        $activeTracks = $this->entityManager->createQueryBuilder()
            ->select('t', 's')
            ->from(Track::class, 't')
            ->innerJoin('t.section', 's')
            ->where('t.inactiveDate IS NULL')
            ->andWhere('s.inactiveDate IS NULL')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($activeTracks as $track) {
            $tracks[(int) $track->getId()] = $track;
        }

        $programs = [];
        /** @var list<Program> $activePrograms */
        $activePrograms = $this->entityManager->getRepository(Program::class)->findActiveForTargeting();
        foreach ($activePrograms as $program) {
            $programs[(int) $program->getId()] = $program;
        }

        // What is already named stays on offer, with the parents that give it a place.
        if (null !== $current) {
            foreach ($current->getTargetPrograms() as $program) {
                $programs[(int) $program->getId()] ??= $program;
                $track = $program->getCohort()?->getTrack();
                if (null !== $track) {
                    $tracks[(int) $track->getId()] ??= $track;
                }
            }
            foreach ($current->getTargetTracks() as $track) {
                $tracks[(int) $track->getId()] ??= $track;
            }
            foreach ($tracks as $track) {
                $section = $track->getSection();
                if (null !== $section) {
                    $sections[(int) $section->getId()] ??= $section;
                }
            }
            foreach ($current->getTargetSections() as $section) {
                $sections[(int) $section->getId()] ??= $section;
            }
        }

        return StructureTargetTree::build(array_values($sections), array_values($tracks), array_values($programs));
    }
}
