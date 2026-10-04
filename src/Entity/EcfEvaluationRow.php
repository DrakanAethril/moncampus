<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcfPart;
use App\Repository\EcfEvaluationRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a « Description des évaluations mises en œuvre » table: what was evaluated, when,
 * and which competences it evaluated.
 *
 * Competences are their numbers, 1 to 9, exactly as the paper ticks them - never keys to Skill:
 * the same competence is a different Skill row in each school year's formation.
 */
#[ORM\Entity(repositoryClass: EcfEvaluationRowRepository::class)]
#[ORM\Table(name: 'ecf_evaluation_row')]
class EcfEvaluationRow
{
    public const int MAX_COMPETENCE = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcfActivity::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private EcfActivity $activity;

    #[ORM\Column(length: 20, enumType: EcfPart::class)]
    private EcfPart $part;

    #[ORM\Column]
    private int $position;

    // Plain text: one line is one printed paragraph.
    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(name: 'evaluated_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $evaluatedOn = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $competences = [];

    public function __construct(EcfActivity $activity, EcfPart $part, int $position)
    {
        $this->activity = $activity;
        $this->part = $part;
        $this->position = $position;
        $activity->addRow($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActivity(): EcfActivity
    {
        return $this->activity;
    }

    public function getPart(): EcfPart
    {
        return $this->part;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** @return list<string> the printed paragraphs, blank lines dropped */
    public function paragraphs(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $this->description) ?: []),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    public function getEvaluatedOn(): ?\DateTimeImmutable
    {
        return $this->evaluatedOn;
    }

    public function setEvaluatedOn(?\DateTimeImmutable $evaluatedOn): static
    {
        $this->evaluatedOn = $evaluatedOn;

        return $this;
    }

    /** @return list<int> */
    public function getCompetences(): array
    {
        return $this->competences;
    }

    /**
     * Kept sorted and unique, and within the paper's nine boxes.
     *
     * @param array<array-key, int> $competences
     */
    public function setCompetences(array $competences): static
    {
        $kept = array_values(array_unique(array_filter($competences, static fn (int $n): bool => $n >= 1 && $n <= self::MAX_COMPETENCE)));
        sort($kept);
        $this->competences = $kept;

        return $this;
    }

    public function isBlank(): bool
    {
        return '' === trim($this->description) && null === $this->evaluatedOn && [] === $this->competences;
    }
}
