<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A practice offer students apply to in order to unlock their school mailbox
 * (design_handoff_workflow_postulation, screens 7b/7c).
 *
 * Fictitious on purpose, and it shows: no company, no address, nothing that could be mistaken for a
 * real opening. A student applies to it exactly as they would to a real one - a written mail, a CV,
 * a cover letter - but the mail never leaves the platform. What is being trained, and reviewed, is
 * the act of applying.
 *
 * Two relations carry the whole behaviour: `validators` decides who may review the applications
 * made on this offer, `visibilityGroups` decides who sees it at all - a student sees the offer when
 * they hold at least one of those groups.
 */
#[ORM\Entity(repositoryClass: TrainingOfferRepository::class)]
#[ORM\Table(name: 'training_offer')]
class TrainingOffer
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** The offer description as a PDF, the only thing a student reads before applying. */
    #[ORM\Column(name: 'document_key', length: 512, nullable: true)]
    private ?string $documentKey = null;

    #[ORM\Column(name: 'document_name', length: 255, nullable: true)]
    private ?string $documentName = null;

    /**
     * @var Collection<int, User>
     *
     * Teachers who receive and review the applications made here. Several by design: a single
     * validator on holiday would freeze every student who applied to their offer.
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'training_offer_validator')]
    private Collection $validators;

    /**
     * @var Collection<int, Group>
     *
     * Who sees the offer: a student needs **at least one group in common** with it. Groups rather
     * than programs because that is what the school already sorts people by, and an offer aimed at
     * "sio-1 and sio-2" is a sentence a teacher can write without thinking about our data model.
     */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'training_offer_group')]
    private Collection $visibilityGroups;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->validators = new ArrayCollection();
        $this->visibilityGroups = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDocumentKey(): ?string
    {
        return $this->documentKey;
    }

    public function setDocumentKey(?string $documentKey): static
    {
        $this->documentKey = $documentKey;

        return $this;
    }

    public function getDocumentName(): ?string
    {
        return $this->documentName;
    }

    public function setDocumentName(?string $documentName): static
    {
        $this->documentName = $documentName;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getValidators(): Collection
    {
        return $this->validators;
    }

    public function addValidator(User $validator): static
    {
        if (!$this->validators->contains($validator)) {
            $this->validators->add($validator);
        }

        return $this;
    }

    public function removeValidator(User $validator): static
    {
        $this->validators->removeElement($validator);

        return $this;
    }

    public function hasValidator(User $user): bool
    {
        foreach ($this->validators as $validator) {
            if ($validator->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Group> */
    public function getVisibilityGroups(): Collection
    {
        return $this->visibilityGroups;
    }

    public function addVisibilityGroup(Group $group): static
    {
        if (!$this->visibilityGroups->contains($group)) {
            $this->visibilityGroups->add($group);
        }

        return $this;
    }

    public function removeVisibilityGroup(Group $group): static
    {
        $this->visibilityGroups->removeElement($group);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
