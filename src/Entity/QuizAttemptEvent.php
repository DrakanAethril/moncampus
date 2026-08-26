<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuizAttemptEventType;
use App\Enum\QuizEventClient;
use App\Repository\QuizAttemptEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dated fact about what the page did during a supervised passation - never a counter on the
 * attempt. Four short flickers and one forty-second absence would give the same integer and have
 * nothing to do with each other; the aggregation belongs to the screens that read this.
 *
 * $position is what makes the row usable: without the question that was on screen at that instant,
 * one knows something happened, not *during which question* - and the whole rule of
 * App\Service\QuizSupervisionAssessor is a coincidence between an absence and a question.
 *
 * $occurredAt is written by the server, at the millisecond. To the second, two beacons landing in
 * the same second would produce absences of 0 s, and the order between "left" and "came back" is
 * exactly what gives the duration.
 *
 * Nothing here is ever deleted by the application; the 12-month retention is a purge
 * (app:purge-platform-activity), announced to the student on the entry contract.
 */
#[ORM\Entity(repositoryClass: QuizAttemptEventRepository::class)]
#[ORM\Table(name: 'quiz_attempt_event')]
#[ORM\Index(name: 'idx_attempt_position', columns: ['attempt_id', 'position'])]
class QuizAttemptEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizAttempt::class)]
    #[ORM\JoinColumn(name: 'attempt_id', nullable: false, onDelete: 'CASCADE')]
    private ?QuizAttempt $attempt = null;

    /** The question on screen at that instant, null when there was none (between two questions). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $position = null;

    #[ORM\Column(length: 32, enumType: QuizAttemptEventType::class)]
    private QuizAttemptEventType $type;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * The millisecond inside $occurredAt's second, 0 to 999.
     *
     * A column of its own rather than a `DATETIME(3)`: Doctrine's own `datetime_immutable` reads and
     * writes to the second, and a custom DBAL type carrying the fraction leaves
     * `doctrine:schema:validate` asking for the same ALTER on every run - a permanent drift, and CI
     * red for ever. The resolution the design asks for is what matters, not where it is stored: two
     * beacons landing inside the same second must not produce an absence of 0 s, and the pair below
     * gives exactly that.
     */
    #[ORM\Column(name: 'occurred_ms', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $occurredMs = 0;

    /**
     * How long the absence this row opened lasted, filled when its counterpart arrives - by
     * difference between two instants the application wrote. Stays null on a type that opens
     * nothing, and on an absence nobody ever came back from (the attempt simply ended there).
     */
    #[ORM\Column(name: 'duration_ms', nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    #[ORM\Column(length: 16, enumType: QuizEventClient::class)]
    private QuizEventClient $client = QuizEventClient::Web;

    public function __construct(QuizAttempt $attempt, QuizAttemptEventType $type, \DateTimeImmutable $occurredAt, QuizEventClient $client)
    {
        $this->attempt = $attempt;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->occurredMs = (int) $occurredAt->format('v');
        $this->client = $client;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttempt(): ?QuizAttempt
    {
        return $this->attempt;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getType(): QuizAttemptEventType
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getOccurredMs(): int
    {
        return $this->occurredMs;
    }

    /**
     * The instant, milliseconds included, as a number of seconds - the form a subtraction wants.
     * Reading the two columns as one is the only place their split shows.
     */
    public function preciseTimestamp(): float
    {
        return $this->occurredAt->getTimestamp() + $this->occurredMs / 1000;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = null === $durationMs ? null : max(0, $durationMs);

        return $this;
    }

    public function getClient(): QuizEventClient
    {
        return $this->client;
    }

    /** An absence this row opened and that nothing has closed yet. */
    public function isOpenAbsence(): bool
    {
        return $this->type->opensAbsence() && null === $this->durationMs;
    }
}
