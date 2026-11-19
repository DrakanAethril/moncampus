<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One flag of a « Balises spécifiques » course and its rank in that race. The rank is the teacher's
 * choice, never the flag's position in the parcours: a race may send the runners to flag 7 before
 * flag 2. Only EcoCourse writes these rows (addSpecificCheckpoint(), removeSpecificCheckpoint(),
 * orderSpecificCheckpoints()), and it keeps the ranks 1..n without a gap.
 *
 * A surrogate id rather than (course, checkpoint) as the key: a course is created with its flags in
 * one flush, before it has an id a derived identity could be built from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'eco_course_specific_checkpoint')]
#[ORM\UniqueConstraint(name: 'eco_course_specific_checkpoint_unique', columns: ['course_id', 'checkpoint_id'])]
class EcoCourseSpecificCheckpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoCourse::class, inversedBy: 'specificSelection')]
    #[ORM\JoinColumn(name: 'course_id', nullable: false, onDelete: 'CASCADE')]
    private EcoCourse $course;

    #[ORM\ManyToOne(targetEntity: EcoCheckpoint::class)]
    #[ORM\JoinColumn(name: 'checkpoint_id', nullable: false, onDelete: 'CASCADE')]
    private EcoCheckpoint $checkpoint;

    // 1 for the first flag to find. `rank` is a reserved word in MySQL 8.
    #[ORM\Column(name: 'race_rank')]
    private int $raceRank;

    public function __construct(EcoCourse $course, EcoCheckpoint $checkpoint, int $raceRank)
    {
        $this->course = $course;
        $this->checkpoint = $checkpoint;
        $this->raceRank = $raceRank;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): EcoCourse
    {
        return $this->course;
    }

    public function getCheckpoint(): EcoCheckpoint
    {
        return $this->checkpoint;
    }

    public function getRaceRank(): int
    {
        return $this->raceRank;
    }

    public function setRaceRank(int $raceRank): static
    {
        $this->raceRank = $raceRank;

        return $this;
    }
}
