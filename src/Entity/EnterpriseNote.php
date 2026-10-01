<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EnterpriseNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A note of the teaching team about a company - « le DSI préfère un appel », « pas de 1re année »
 * (design/validated/vivier-entreprises.md, D10). A thread of signed, dated notes, the shape of
 * JobSearchNote, never one field somebody overwrites.
 *
 * **Never read by a student**: not on the fiche, not on a result, not in an export, not in the
 * mobile API. EnterpriseVoter::VIEW_STAFF_NOTES is the only door, and a functional test checks the
 * fiche a student opens holds the text of none.
 */
#[ORM\Entity(repositoryClass: EnterpriseNoteRepository::class)]
#[ORM\Table(name: 'enterprise_note')]
#[ORM\Index(name: 'idx_enterprise_note_enterprise', columns: ['enterprise_id'])]
class EnterpriseNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false, onDelete: 'CASCADE')]
    private ?Enterprise $enterprise = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    private string $body = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Enterprise $enterprise, User $author, string $body)
    {
        $this->enterprise = $enterprise;
        $this->author = $author;
        $this->body = trim($body);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function edit(string $body): static
    {
        $this->body = trim($body);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
