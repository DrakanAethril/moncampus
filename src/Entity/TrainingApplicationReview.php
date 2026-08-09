<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrainingApplicationDecision;
use App\Enum\TrainingApplicationElement;
use App\Repository\TrainingApplicationReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One validator's verdict on one element of one version
 * (design_handoff_workflow_postulation, screen 8d).
 *
 * A row per decision rather than a mutable status per element: the handoff asks for who validated
 * what and when, and for a validation acquired on v1 to still hold on v2. Both fall out of keeping
 * decisions as facts that happened, instead of a state that gets overwritten.
 */
#[ORM\Entity(repositoryClass: TrainingApplicationReviewRepository::class)]
#[ORM\Table(name: 'training_application_review')]
#[ORM\Index(name: 'idx_training_application_review_application', columns: ['application_id'])]
class TrainingApplicationReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingApplication::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingApplication $application = null;

    #[ORM\Column(length: 20, enumType: TrainingApplicationElement::class)]
    private TrainingApplicationElement $element;

    #[ORM\Column(length: 20, enumType: TrainingApplicationDecision::class)]
    private TrainingApplicationDecision $decision = TrainingApplicationDecision::Pending;

    /** Required when refusing: a correction asked for without a reason cannot be acted on. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remark = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'validator_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $validator = null;

    /** The version this verdict was taken on - what lets screen 8d say "Validé (v1)". */
    #[ORM\Column(name: 'version_number', type: Types::INTEGER)]
    private int $versionNumber = 1;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $decidedAt;

    public function __construct()
    {
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ?TrainingApplication
    {
        return $this->application;
    }

    public function setApplication(?TrainingApplication $application): static
    {
        $this->application = $application;

        return $this;
    }

    public function getElement(): TrainingApplicationElement
    {
        return $this->element;
    }

    public function setElement(TrainingApplicationElement $element): static
    {
        $this->element = $element;

        return $this;
    }

    public function getDecision(): TrainingApplicationDecision
    {
        return $this->decision;
    }

    public function setDecision(TrainingApplicationDecision $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }

    public function getValidator(): ?User
    {
        return $this->validator;
    }

    public function setValidator(?User $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): static
    {
        $this->versionNumber = $versionNumber;

        return $this;
    }

    public function getDecidedAt(): \DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
