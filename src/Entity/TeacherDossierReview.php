<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DossierReviewAction;
use App\Repository\TeacherDossierReviewRepository;
use App\Service\Dossier\DepositReview;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a member of the administration answered to a dépôt — validated, or correction requested.
 *
 * Append-only, like App\Entity\DossierReview: somebody who changes their mind writes a second
 * review. A correction request cannot be built without its comment - a refusal with nothing to
 * correct is one the intervenant cannot act on.
 */
#[ORM\Entity(repositoryClass: TeacherDossierReviewRepository::class)]
#[ORM\Table(name: 'teacher_dossier_review')]
class TeacherDossierReview implements DepositReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TeacherDossierSubmission::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'submission_id', nullable: false, onDelete: 'CASCADE')]
    private ?TeacherDossierSubmission $submission = null;

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
    public function __construct(TeacherDossierSubmission $submission, User $author, DossierReviewAction $action, ?string $comment)
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

    public function getSubmission(): ?TeacherDossierSubmission
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

    #[\Override]
    public function isValidation(): bool
    {
        return DossierReviewAction::Validated === $this->action;
    }
}
