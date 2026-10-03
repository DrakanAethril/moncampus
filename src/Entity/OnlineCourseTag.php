<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineCourseTagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A tag of the online courses - one author's own vocabulary, created as they type
 * (design/validated/cours-en-ligne.md, §9).
 *
 * Per author rather than shared, like the séquence library's tags: there is no common catalogue,
 * so a tag only ever filters its author's page. $normalizedLabel is what makes « SQL », « sql » and
 * « Sql » one tag; it is also what a common catalogue would group on, the day one is asked for,
 * without migrating anything.
 */
#[ORM\Entity(repositoryClass: OnlineCourseTagRepository::class)]
#[ORM\Table(name: 'online_course_tag')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_tag_owner_label', columns: ['owner_id', 'normalized_label'])]
class OnlineCourseTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $label;

    #[ORM\Column(length: 100)]
    private string $normalizedLabel;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $label)
    {
        $this->owner = $owner;
        $this->label = '';
        $this->normalizedLabel = '';
        $this->setLabel($label);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        \assert(null !== $this->owner);

        return $this->owner;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr(trim($label), 0, 100);
        $this->normalizedLabel = self::normalize($this->label);

        return $this;
    }

    public function getNormalizedLabel(): string
    {
        return $this->normalizedLabel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Case, accents and runs of spaces are not what tells two tags apart: « Réseau » and « reseau »
     * are the same word typed twice.
     */
    public static function normalize(string $label): string
    {
        $folded = \Normalizer::normalize(mb_strtolower(trim($label)), \Normalizer::FORM_D);
        $ascii = preg_replace('/\p{Mn}+/u', '', false === $folded ? mb_strtolower(trim($label)) : $folded) ?? '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $ascii)), 0, 100);
    }
}
