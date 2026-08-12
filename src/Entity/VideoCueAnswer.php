<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoCueAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one student answered at one marker. Right or wrong, and when - nothing else.
 *
 * Deliberately NOT a grade (decided 2026-08-12): these rows feed the teacher's per-question success
 * reading and the student's own "revoir le passage", and they are wired to no gradebook. A measure
 * that quietly becomes a mark changes what a student does with a video.
 *
 * One row per student and marker, written the first time and never overwritten: the second pass
 * happens after the correction has been read, so keeping it would measure the correction rather
 * than the teaching.
 */
#[ORM\Entity(repositoryClass: VideoCueAnswerRepository::class)]
#[ORM\Table(name: 'video_cue_answer')]
#[ORM\UniqueConstraint(name: 'uniq_video_cue_answer_student', columns: ['cue_point_id', 'student_id'])]
class VideoCueAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoCuePoint::class)]
    #[ORM\JoinColumn(name: 'cue_point_id', nullable: false, onDelete: 'CASCADE')]
    private ?VideoCuePoint $cuePoint = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column]
    private bool $correct = false;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $answeredAt;

    public function __construct(VideoCuePoint $cuePoint, User $student, bool $correct)
    {
        $this->cuePoint = $cuePoint;
        $this->student = $student;
        $this->correct = $correct;
        $this->answeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCuePoint(): ?VideoCuePoint
    {
        return $this->cuePoint;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function getAnsweredAt(): \DateTimeImmutable
    {
        return $this->answeredAt;
    }
}
