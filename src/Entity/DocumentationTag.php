<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentationTagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A tag of the documentation base - "Certifications", "Vie du campus", "Orientation".
 *
 * Unlike the teaching library's tags (App\Entity\AbstractLibraryTag), this vocabulary is *shared*:
 * one referential for the whole campus, created on the fly from the editor and cleaned up from the
 * tag administration screen (rename/merge/delete). $normalizedLabel is what makes "Certifications"
 * and "certifications " the same tag rather than two rows nobody can tell apart.
 */
#[ORM\Entity(repositoryClass: DocumentationTagRepository::class)]
#[ORM\Table(name: 'documentation_tag')]
#[ORM\UniqueConstraint(name: 'uniq_documentation_tag_normalized', columns: ['normalized_label'])]
class DocumentationTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $label;

    #[ORM\Column(name: 'normalized_label', length: 100)]
    private string $normalizedLabel;

    /** @var Collection<int, DocumentationArticle> */
    #[ORM\ManyToMany(targetEntity: DocumentationArticle::class, mappedBy: 'tags')]
    private Collection $articles;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $label)
    {
        $this->setLabel($label);
        $this->articles = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = trim($label);
        $this->normalizedLabel = self::normalize($label);

        return $this;
    }

    public function getNormalizedLabel(): string
    {
        return $this->normalizedLabel;
    }

    /** @return Collection<int, DocumentationArticle> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Case and surrounding spaces are what separate a duplicate from a distinct tag here;
     * accents are not folded, "Élèves" and "Eleves" being two different words.
     */
    public static function normalize(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
