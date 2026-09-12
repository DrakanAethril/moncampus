<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccommodationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An arrangement granted to a student with particular needs (« un aménagement »), e.g. a third more
 * time on timed work.
 *
 * Deliberately **not** an AbstractStructureNode and deliberately not linked to Program: unlike
 * App\Entity\Option and App\Entity\Modality, which describe how a *formation* is followed and are
 * therefore attached to the programs offering them, an accommodation describes a *person*. It is
 * available to every student of the establishment and is granted one account at a time
 * (App\Entity\User::$accommodations, set from the annuaire fiche). It carries no LDAP group for the
 * same reason the fiche restricts who sees it at all - who has one is not something to publish into
 * a directory.
 *
 * **Each criterion is a column of its own, and every reader goes through
 * App\Service\Accommodation\AccommodationProfile.** A student may hold several accommodations, so no
 * single row is ever the answer: the profile is what combines them (for extra time, the largest
 * percentage wins). Adding a second criterion is therefore three gestures - a column here, a field
 * on App\Form\AccommodationType, a line in the profile - and no consumer changes, which is the point
 * of the indirection: the criteria that will be added later are meant to steer other parts of the
 * platform, not only the quizzes.
 */
#[ORM\Entity(repositoryClass: AccommodationRepository::class)]
#[ORM\Table(name: 'accommodation')]
class Accommodation
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /**
     * How much time this accommodation adds to timed quizzes, as a percentage (33.33 for "a third
     * more"). Null - the default - means this accommodation says nothing about time, which is not
     * the same as 0: an accommodation that will only ever carry a future criterion must not be read
     * as "grants no extra time" any louder than the row next to it.
     *
     * Decimal rather than float because the value is typed by a human and read back to them: 33.33
     * must come back as 33.33. Doctrine hands decimals over as strings, hence the ?string property -
     * App\Service\Accommodation\AccommodationProfile is where it becomes a number.
     */
    #[ORM\Column(name: 'quiz_extra_time_percent', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(500)]
    private ?string $quizExtraTimePercent = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getQuizExtraTimePercent(): ?string
    {
        return $this->quizExtraTimePercent;
    }

    public function setQuizExtraTimePercent(?string $quizExtraTimePercent): static
    {
        $this->quizExtraTimePercent = $quizExtraTimePercent;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
