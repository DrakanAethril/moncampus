<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConsoleSnippetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A command somebody keeps retyping, kept once.
 *
 * The personal half of the command palette. The other half - the platform catalogue - is
 * deliberately **not** a table but a PHP class (App\Console\ConsoleSnippetCatalog), on the model of
 * App\Help\HelpContentCatalog: nobody edits those from a screen, they arrive with a deploy, exactly
 * as the changelog does.
 *
 * `shared` is the whole of the collaboration: a snippet marked shared is readable by the other
 * teachers and by the administrators, and shows in their palette with its author's name beside it.
 * There is no shape between « mine » and « everybody's », because the population that reads this
 * screen is the handful of people who may open a console at all.
 *
 * `useCount` and `lastUsedAt` are not statistics - they are the ordering. What somebody uses most
 * comes first, which is the difference between a palette and a list.
 */
#[ORM\Entity(repositoryClass: ConsoleSnippetRepository::class)]
#[ORM\Table(name: 'console_snippet')]
#[ORM\Index(name: 'idx_console_snippet_owner', columns: ['owner_id'])]
class ConsoleSnippet
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: Types::TEXT)]
    private string $command;

    /** Readable by the other teachers and the administrators - see the class docblock. */
    #[ORM\Column(options: ['default' => false])]
    private bool $shared = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(name: 'use_count', options: ['default' => 0])]
    private int $useCount = 0;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(User $owner, string $label, string $command)
    {
        $this->owner = $owner;
        $this->label = $label;
        $this->command = $command;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** Counted at insertion, not at execution: what gets picked is what gets offered first. */
    public function markUsed(): static
    {
        ++$this->useCount;
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }
}
