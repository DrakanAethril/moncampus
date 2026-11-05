<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizLiveAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One participant's answer to one question of a QuizLiveSession - the live-mode counterpart of
 * QuizAttemptAnswer. The answer itself is one JSON value whatever the type
 * (App\Service\QuizLive\LiveResponse: the propositions tapped, an order, a number, a word), since
 * the room plays every type a Kahoot carries. Unique per (participant, instanceQuestion) -
 * submitAnswer() is first-write-wins, a duplicate/late POST for the same question is a no-op,
 * mirroring ProgramQuizAttemptController::answer()'s ignore-and-move-on pattern.
 */
#[ORM\Entity(repositoryClass: QuizLiveAnswerRepository::class)]
#[ORM\Table(name: 'quiz_live_answer')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_live_answer_participant_question', columns: ['participant_id', 'instance_question_id'])]
class QuizLiveAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizLiveParticipant::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'participant_id', nullable: false)]
    private ?QuizLiveParticipant $participant = null;

    #[ORM\ManyToOne(targetEntity: QuizInstanceQuestion::class)]
    #[ORM\JoinColumn(name: 'instance_question_id', nullable: false)]
    private ?QuizInstanceQuestion $instanceQuestion = null;

    // App\Service\QuizLive\LiveResponse::toArray(). Null = no answer submitted before the
    // question closed (locked out).
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $response = null;

    // Computed once at submit time via App\Service\QuizAttemptGrader::isCorrect() (reused as-is),
    // then frozen - same convention as QuizAttemptAnswer::$isCorrect. Null for a vote, which is
    // neither right nor wrong.
    #[ORM\Column(name: 'is_correct', nullable: true)]
    private ?bool $isCorrect = null;

    // Server receipt timestamp - the speed-bonus input, see QuizLiveSessionService's scoring
    // formula. Null for the backfilled rows created at Question->Results for non-answerers.
    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(name: 'points_awarded')]
    private int $pointsAwarded = 0;

    public function __construct(QuizLiveParticipant $participant, QuizInstanceQuestion $instanceQuestion)
    {
        $this->participant = $participant;
        $this->instanceQuestion = $instanceQuestion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParticipant(): ?QuizLiveParticipant
    {
        return $this->participant;
    }

    public function getInstanceQuestion(): ?QuizInstanceQuestion
    {
        return $this->instanceQuestion;
    }

    /** @return array<string, mixed>|null */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /** @param array<string, mixed>|null $response */
    public function setResponse(?array $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function getIsCorrect(): ?bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(?bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(?\DateTimeImmutable $answeredAt): static
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function getPointsAwarded(): int
    {
        return $this->pointsAwarded;
    }

    public function setPointsAwarded(int $pointsAwarded): static
    {
        $this->pointsAwarded = $pointsAwarded;

        return $this;
    }
}
