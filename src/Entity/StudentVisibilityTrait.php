<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentVisibility;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Whether a piece of course content is readable by the students of its Program, and since when.
 *
 * Mixed into SequenceInstance and SeanceInstance - the two levels the course space publishes.
 * Phases deliberately do not carry it: SeancePhaseInstance holds the teacher's own choreography
 * (enseignant, difficultes, moyensSupports), which is never exposed, so a third level of visibility
 * would be a setting with nothing behind it.
 *
 * The default is Hidden on both, so nothing of a newly instantiated sequence reaches a student
 * before a teacher says so - the same reflex the cahier de texte applies to its sections.
 */
trait StudentVisibilityTrait
{
    #[ORM\Column(name: 'student_visibility', length: 20, enumType: ContentVisibility::class, options: ['default' => 'hidden'])]
    private ContentVisibility $studentVisibility = ContentVisibility::Hidden;

    // Only meaningful for ContentVisibility::Scheduled; kept null otherwise by setStudentVisibility()
    // so a state left over from an earlier choice can never resurface.
    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getStudentVisibility(): ContentVisibility
    {
        return $this->studentVisibility;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Set as a pair: a date only survives on the one state that uses it. Storing them separately is
     * how a "Scheduled 12 September" left behind by a since-published entry comes back the day
     * someone switches it back to Scheduled.
     */
    public function setStudentVisibility(ContentVisibility $visibility, ?\DateTimeImmutable $publishedAt = null): static
    {
        $this->studentVisibility = $visibility;
        $this->publishedAt = $visibility->needsDate() ? $publishedAt : null;

        return $this;
    }

    public function isVisibleToStudentsAt(?\DateTimeImmutable $now = null): bool
    {
        return $this->studentVisibility->isVisibleAt($this->publishedAt, $now ?? new \DateTimeImmutable());
    }
}
