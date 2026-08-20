<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveySeriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What makes two campaigns comparable - and the only thing that does (surveys.md §3).
 *
 * Comparability is *declared*, never guessed: two waves are comparable because they belong to the
 * same series, full stop. Testing the equality of two campaigns' targets and question labels would
 * give a fragile heuristic - a student changing class would silently erase the comparison.
 *
 * A series is born at the first launch. Its link back to the template is indicative and may
 * disappear (ON DELETE SET NULL): the series stands on its waves' own snapshots.
 */
#[ORM\Entity(repositoryClass: SurveySeriesRepository::class)]
#[ORM\Table(name: 'survey_series')]
class SurveySeries
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: SurveyTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', nullable: true, onDelete: 'SET NULL')]
    private ?SurveyTemplate $template = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, SurveyCampaign> */
    #[ORM\OneToMany(mappedBy: 'series', targetEntity: SurveyCampaign::class)]
    #[ORM\OrderBy(['waveNumber' => 'ASC'])]
    private Collection $campaigns;

    public function __construct()
    {
        $this->campaigns = new ArrayCollection();
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getTemplate(): ?SurveyTemplate
    {
        return $this->template;
    }

    public function setTemplate(?SurveyTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, SurveyCampaign> */
    public function getCampaigns(): Collection
    {
        return $this->campaigns;
    }

    public function addCampaign(SurveyCampaign $campaign): static
    {
        if (!$this->campaigns->contains($campaign)) {
            $this->campaigns->add($campaign);
        }

        return $this;
    }

    /** The wave number the next launch in this series gets. */
    public function nextWaveNumber(): int
    {
        $highest = 0;
        foreach ($this->campaigns as $campaign) {
            $highest = max($highest, $campaign->getWaveNumber());
        }

        return $highest + 1;
    }
}
