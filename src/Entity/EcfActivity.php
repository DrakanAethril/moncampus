<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Repository\EcfActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a booklet holds for one activity-type: its « Fiche de résultats » and the « Évaluations
 * complémentaires » page that follows it.
 *
 * The activity-type itself is a competency group of the formation, found again by its code
 * (`AT1`, `AT2`…) from one school year to the next - the groups are different rows each year. The
 * labels are copied here at the first visa ($frozenLabel, $frozenCompetences) so a signed part
 * keeps printing what was signed even if the référentiel is edited afterwards.
 */
#[ORM\Entity(repositoryClass: EcfActivityRepository::class)]
#[ORM\Table(name: 'ecf_activity')]
#[ORM\UniqueConstraint(name: 'uniq_ecf_activity_booklet_code', columns: ['booklet_id', 'group_code'])]
class EcfActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcfBooklet::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(name: 'booklet_id', nullable: false, onDelete: 'CASCADE')]
    private EcfBooklet $booklet;

    #[ORM\Column(name: 'group_code', length: 20)]
    private string $groupCode;

    #[ORM\Column(length: 20, nullable: true, enumType: EcfResult::class)]
    private ?EcfResult $result = null;

    #[ORM\Column(name: 'complementary_result', length: 20, nullable: true, enumType: EcfResult::class)]
    private ?EcfResult $complementaryResult = null;

    #[ORM\Column(name: 'attention_points', type: Types::TEXT, nullable: true)]
    private ?string $attentionPoints = null;

    #[ORM\Column(name: 'reassess_note', type: Types::TEXT, nullable: true)]
    private ?string $reassessNote = null;

    /** @var list<int> competence numbers, 1 to 9 */
    #[ORM\Column(name: 'reassess_competences', type: Types::JSON)]
    private array $reassessCompetences = [];

    #[ORM\Column(name: 'complementary_observations', type: Types::TEXT, nullable: true)]
    private ?string $complementaryObservations = null;

    #[ORM\Column(name: 'frozen_label', length: 255, nullable: true)]
    private ?string $frozenLabel = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'frozen_competences', type: Types::JSON, nullable: true)]
    private ?array $frozenCompetences = null;

    /** @var Collection<int, EcfEvaluationRow> */
    #[ORM\OneToMany(targetEntity: EcfEvaluationRow::class, mappedBy: 'activity', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $rows;

    public function __construct(EcfBooklet $booklet, string $groupCode)
    {
        $this->booklet = $booklet;
        $this->groupCode = $groupCode;
        $this->rows = new ArrayCollection();
        $booklet->addActivity($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooklet(): EcfBooklet
    {
        return $this->booklet;
    }

    public function getGroupCode(): string
    {
        return $this->groupCode;
    }

    public function getResult(): ?EcfResult
    {
        return $this->result;
    }

    public function setResult(?EcfResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function getComplementaryResult(): ?EcfResult
    {
        return $this->complementaryResult;
    }

    public function setComplementaryResult(?EcfResult $complementaryResult): static
    {
        $this->complementaryResult = $complementaryResult;

        return $this;
    }

    public function resultOf(EcfPart $part): ?EcfResult
    {
        return EcfPart::Complementary === $part ? $this->complementaryResult : $this->result;
    }

    public function getAttentionPoints(): ?string
    {
        return $this->attentionPoints;
    }

    public function setAttentionPoints(?string $attentionPoints): static
    {
        $this->attentionPoints = $attentionPoints;

        return $this;
    }

    public function getReassessNote(): ?string
    {
        return $this->reassessNote;
    }

    public function setReassessNote(?string $reassessNote): static
    {
        $this->reassessNote = $reassessNote;

        return $this;
    }

    /** @return list<int> */
    public function getReassessCompetences(): array
    {
        return $this->reassessCompetences;
    }

    /** @param array<array-key, int> $reassessCompetences */
    public function setReassessCompetences(array $reassessCompetences): static
    {
        $this->reassessCompetences = array_values($reassessCompetences);

        return $this;
    }

    public function getComplementaryObservations(): ?string
    {
        return $this->complementaryObservations;
    }

    public function setComplementaryObservations(?string $complementaryObservations): static
    {
        $this->complementaryObservations = $complementaryObservations;

        return $this;
    }

    public function getFrozenLabel(): ?string
    {
        return $this->frozenLabel;
    }

    /** @return list<string>|null */
    public function getFrozenCompetences(): ?array
    {
        return $this->frozenCompetences;
    }

    /** @param array<array-key, string> $competences */
    public function freeze(string $label, array $competences): static
    {
        $this->frozenLabel = $label;
        $this->frozenCompetences = array_values($competences);

        return $this;
    }

    public function unfreeze(): static
    {
        $this->frozenLabel = null;
        $this->frozenCompetences = null;

        return $this;
    }

    public function isFrozen(): bool
    {
        return null !== $this->frozenLabel;
    }

    /** @return Collection<int, EcfEvaluationRow> */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    /** @return list<EcfEvaluationRow> */
    public function rowsOf(EcfPart $part): array
    {
        $rows = array_values(array_filter($this->rows->toArray(), static fn (EcfEvaluationRow $row): bool => $row->getPart() === $part));
        usort($rows, static fn (EcfEvaluationRow $a, EcfEvaluationRow $b): int => $a->getPosition() <=> $b->getPosition());

        return $rows;
    }

    public function addRow(EcfEvaluationRow $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
        }

        return $this;
    }

    public function removeRow(EcfEvaluationRow $row): static
    {
        $this->rows->removeElement($row);

        return $this;
    }
}
