<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriousGameAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One question of a session, served then answered (design/validated/serious-games.md §4).
 *
 * **The statement is never stored**: `$seed` and `$templateVersion` give it back exactly, which is
 * how a teacher replays « la question vue » months later. What is stored is what the student did -
 * the input as typed, whether it was right, the typical error it was recognised as, or « je ne
 * sais pas » (which names no error).
 *
 * The row is written when the question is served (`$servedAt`) and completed when it is answered;
 * an unanswered row is a question shown and left.
 */
#[ORM\Entity(repositoryClass: SeriousGameAnswerRepository::class)]
#[ORM\Table(name: 'serious_game_answer')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_answer_position', columns: ['session_id', 'position'])]
#[ORM\Index(name: 'idx_serious_game_answer_misconception', columns: ['misconception'])]
class SeriousGameAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SeriousGameSession::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private ?SeriousGameSession $session = null;

    #[ORM\Column(name: 'position', type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(name: 'unit_code', length: 40)]
    private string $unitCode;

    #[ORM\Column(name: 'template_version')]
    private int $templateVersion;

    #[ORM\Column(name: 'seed', type: Types::BIGINT)]
    private int $seed;

    #[ORM\Column(name: 'input', length: 500, nullable: true)]
    private ?string $input = null;

    #[ORM\Column(name: 'ok', nullable: true)]
    private ?bool $ok = null;

    #[ORM\Column(name: 'misconception', length: 80, nullable: true)]
    private ?string $misconception = null;

    #[ORM\Column(name: 'dont_know')]
    private bool $dontKnow = false;

    #[ORM\Column(name: 'ms', nullable: true)]
    private ?int $ms = null;

    #[ORM\Column(name: 'served_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $servedAt;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct(SeriousGameSession $session, int $position, string $unitCode, int $templateVersion, int $seed)
    {
        $this->session = $session;
        $this->position = $position;
        $this->unitCode = $unitCode;
        $this->templateVersion = $templateVersion;
        $this->seed = $seed;
        $this->servedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): SeriousGameSession
    {
        \assert(null !== $this->session);

        return $this->session;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getTemplateVersion(): int
    {
        return $this->templateVersion;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function isOk(): bool
    {
        return true === $this->ok;
    }

    public function isAnswered(): bool
    {
        return null !== $this->answeredAt;
    }

    public function getMisconception(): ?string
    {
        return $this->misconception;
    }

    public function isDontKnow(): bool
    {
        return $this->dontKnow;
    }

    public function getMs(): ?int
    {
        return $this->ms;
    }

    public function getServedAt(): \DateTimeImmutable
    {
        return $this->servedAt;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function answer(?string $input, bool $ok, ?string $misconception, bool $dontKnow): static
    {
        $this->answeredAt = new \DateTimeImmutable();
        $this->input = null === $input ? null : mb_substr($input, 0, 500);
        $this->ok = $ok;
        $this->misconception = $ok || $dontKnow ? null : $misconception;
        $this->dontKnow = $dontKnow;
        $this->ms = max(0, (int) round(($this->answeredAt->format('U.u') - $this->servedAt->format('U.u')) * 1000));

        return $this;
    }
}
