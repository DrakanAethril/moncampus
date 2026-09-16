<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DossierReviewAction;
use App\Repository\DossierReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing a validateur said about a dépôt — validated, or corrections requested.
 *
 * Append-only, like GameEntry: a validateur who changes their mind writes a *second* review, they do
 * not edit the first. That is what makes the historique on the validation screen an account of what
 * happened rather than of what somebody currently thinks, and it is why the comment of a correction
 * request survives the correction being made.
 *
 * The comment is mandatory on a correction request and the constructor refuses to build one without
 * it - see App\Enum\DossierReviewAction::requiresComment().
 */
#[ORM\Entity(repositoryClass: DossierReviewRepository::class)]
#[ORM\Table(name: 'dossier_review')]
class DossierReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierSubmission::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'submission_id', nullable: false, onDelete: 'CASCADE')]
    private ?DossierSubmission $submission = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false)]
    private ?User $author = null;

    #[ORM\Column(length: 30, enumType: DossierReviewAction::class)]
    private DossierReviewAction $action;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @throws \InvalidArgumentException when a correction is requested with nothing to correct
     */
    public function __construct(DossierSubmission $submission, User $author, DossierReviewAction $action, ?string $comment)
    {
        $comment = null === $comment ? null : trim($comment);

        if ($action->requiresComment() && (null === $comment || '' === $comment)) {
            throw new \InvalidArgumentException('A correction request must carry a comment.');
        }

        $this->submission = $submission;
        $this->author = $author;
        $this->action = $action;
        $this->comment = '' === $comment ? null : $comment;
        $this->createdAt = new \DateTimeImmutable();

        $submission->addReview($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubmission(): ?DossierSubmission
    {
        return $this->submission;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAction(): DossierReviewAction
    {
        return $this->action;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isValidation(): bool
    {
        return DossierReviewAction::Validated === $this->action;
    }
}
