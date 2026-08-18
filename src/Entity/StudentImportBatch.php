<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StudentImportBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One class import that actually ran: which file, into which class, by whom, and what it wrote.
 *
 * It exists because a session does not survive the tab being closed, and the directory does not
 * create the accounts instantly - a script on the server picks the queue up every minute. The
 * follow-up screen has to stay reachable the next day, so what it shows has to be a row.
 *
 * The state of each account is deliberately NOT stored here: it is read live off
 * App\Entity\LdapManageUser::$state through the batch's lines. Nothing to synchronise, therefore
 * nothing that can fall out of sync.
 */
#[ORM\Entity(repositoryClass: StudentImportBatchRepository::class)]
#[ORM\Table(name: 'student_import_batch')]
class StudentImportBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    #[ORM\Column(name: 'file_name', length: 255)]
    private string $fileName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'imported_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $importedBy = null;

    #[ORM\Column(name: 'imported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(name: 'created_count', options: ['default' => 0])]
    private int $createdCount = 0;

    #[ORM\Column(name: 'attached_count', options: ['default' => 0])]
    private int $attachedCount = 0;

    #[ORM\Column(name: 'updated_count', options: ['default' => 0])]
    private int $updatedCount = 0;

    /** @var Collection<int, StudentImportBatchLine> */
    #[ORM\OneToMany(mappedBy: 'batch', targetEntity: StudentImportBatchLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct(Program $program, string $fileName, ?User $importedBy)
    {
        $this->program = $program;
        $this->fileName = $fileName;
        $this->importedBy = $importedBy;
        $this->importedAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getImportedBy(): ?User
    {
        return $this->importedBy;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function setCreatedCount(int $createdCount): static
    {
        $this->createdCount = $createdCount;

        return $this;
    }

    public function getAttachedCount(): int
    {
        return $this->attachedCount;
    }

    public function setAttachedCount(int $attachedCount): static
    {
        $this->attachedCount = $attachedCount;

        return $this;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function setUpdatedCount(int $updatedCount): static
    {
        $this->updatedCount = $updatedCount;

        return $this;
    }

    /** @return Collection<int, StudentImportBatchLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(StudentImportBatchLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }

        return $this;
    }
}
