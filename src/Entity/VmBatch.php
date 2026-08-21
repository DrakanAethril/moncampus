<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VmBatchItemStatus;
use App\Enum\VmBatchShape;
use App\Repository\VmBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One machine per student of a class, deployed in one action.
 *
 * The link to the pedagogical side is deliberately **two foreign keys and no more** - which class a
 * batch is for, and which account belongs to which person. Everything else about a Program stays
 * where it belongs; this is an infrastructure feature that happens to know what a class is.
 *
 * Within the class, a batch can be narrowed to the students following particular Options and/or
 * Modalities. An empty set on either side means "everyone", which is why neither join table has a
 * row meaning "all" - the absence is the meaning, the same convention as the shared-documents
 * targeting.
 *
 * `expiresAt` exists because these machines are for a six-week module and nobody ever remembers to
 * clean them up. It does **not** destroy anything - the application never does - it makes the batch
 * show as expired and reminds, and an administrator deletes in Proxmox.
 */
#[ORM\Entity(repositoryClass: VmBatchRepository::class)]
#[ORM\Table(name: 'vm_batch')]
class VmBatch
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'vm_batch_option')]
    private Collection $options;

    /** @var Collection<int, Modality> */
    #[ORM\ManyToMany(targetEntity: Modality::class)]
    #[ORM\JoinTable(name: 'vm_batch_modality')]
    private Collection $modalities;

    #[ORM\Column(length: 20, enumType: VmBatchShape::class)]
    private VmBatchShape $shape = VmBatchShape::PerStudent;

    // The saved set of groups a PerGroup batch was planned from - NULL for a PerStudent one, which
    // targets the class roster instead. Nothing is unique about the link: the same set may be
    // turned into machines again next term, and the earlier batch is not touched. SET NULL rather
    // than CASCADE because deleting the set must not delete a batch whose machines exist - the plan
    // it produced lives on in the items, which carry their own snapshot of who is on each machine.
    #[ORM\ManyToOne(targetEntity: GroupBatch::class)]
    #[ORM\JoinColumn(name: 'group_batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?GroupBatch $groupBatch = null;

    #[ORM\ManyToOne(targetEntity: ProxmoxHost::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: false)]
    private ?ProxmoxHost $host = null;

    #[ORM\ManyToOne(targetEntity: IpRange::class)]
    #[ORM\JoinColumn(name: 'ip_range_id', nullable: false)]
    private ?IpRange $ipRange = null;

    #[ORM\Column(name: 'template_vmid')]
    private int $templateVmid;

    #[ORM\Column(length: 64)]
    private string $node;

    #[ORM\Column(length: 64)]
    private string $storage = 'local-lvm';

    #[ORM\Column]
    #[Assert\Positive]
    private int $cores = 2;

    #[ORM\Column(name: 'memory_mib')]
    #[Assert\Positive]
    private int $memoryMib = 2048;

    #[ORM\Column(name: 'disk_gib')]
    #[Assert\Positive]
    private int $diskGib = 16;

    #[ORM\Column(name: 'linked_clone')]
    private bool $linkedClone = false;

    /**
     * How each machine is named. `{index}` and `{login}` are substituted per student, which is what
     * makes twenty-four names predictable rather than twenty-four decisions.
     */
    #[ORM\Column(name: 'name_pattern', length: 64)]
    #[Assert\NotBlank]
    private string $namePattern = 'tp-{index}';

    #[ORM\Column(name: 'post_install_script', type: Types::TEXT, nullable: true)]
    private ?string $postInstallScript = null;

    /** Whether each student's account gets sudo on their own machine. */
    #[ORM\Column(name: 'expires_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'reminded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    /** @var Collection<int, VmBatchItem> */
    #[ORM\OneToMany(targetEntity: VmBatchItem::class, mappedBy: 'batch', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, GuestAccount> */
    #[ORM\OneToMany(targetEntity: GuestAccount::class, mappedBy: 'batch')]
    private Collection $accounts;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, Program $program, ProxmoxHost $host, IpRange $ipRange, int $templateVmid, string $node)
    {
        $this->label = $label;
        $this->program = $program;
        $this->host = $host;
        $this->ipRange = $ipRange;
        $this->templateVmid = $templateVmid;
        $this->node = $node;
        $this->creationDate = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
        $this->modalities = new ArrayCollection();
        $this->items = new ArrayCollection();
        $this->accounts = new ArrayCollection();
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
        $this->label = $label;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    /** @return Collection<int, Modality> */
    public function getModalities(): Collection
    {
        return $this->modalities;
    }

    public function addModality(Modality $modality): static
    {
        if (!$this->modalities->contains($modality)) {
            $this->modalities->add($modality);
        }

        return $this;
    }

    public function removeModality(Modality $modality): static
    {
        $this->modalities->removeElement($modality);

        return $this;
    }

    public function getShape(): VmBatchShape
    {
        return $this->shape;
    }

    public function setShape(VmBatchShape $shape): static
    {
        $this->shape = $shape;

        return $this;
    }

    public function getGroupBatch(): ?GroupBatch
    {
        return $this->groupBatch;
    }

    public function setGroupBatch(?GroupBatch $groupBatch): static
    {
        $this->groupBatch = $groupBatch;

        return $this;
    }

    public function getHost(): ?ProxmoxHost
    {
        return $this->host;
    }

    public function setHost(?ProxmoxHost $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getIpRange(): ?IpRange
    {
        return $this->ipRange;
    }

    public function setIpRange(?IpRange $ipRange): static
    {
        $this->ipRange = $ipRange;

        return $this;
    }

    public function getTemplateVmid(): int
    {
        return $this->templateVmid;
    }

    public function setTemplateVmid(int $templateVmid): static
    {
        $this->templateVmid = $templateVmid;

        return $this;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    public function setNode(string $node): static
    {
        $this->node = $node;

        return $this;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function setStorage(string $storage): static
    {
        $this->storage = $storage;

        return $this;
    }

    public function getCores(): int
    {
        return $this->cores;
    }

    public function setCores(int $cores): static
    {
        $this->cores = $cores;

        return $this;
    }

    public function getMemoryMib(): int
    {
        return $this->memoryMib;
    }

    public function setMemoryMib(int $memoryMib): static
    {
        $this->memoryMib = $memoryMib;

        return $this;
    }

    public function getDiskGib(): int
    {
        return $this->diskGib;
    }

    public function setDiskGib(int $diskGib): static
    {
        $this->diskGib = $diskGib;

        return $this;
    }

    public function isLinkedClone(): bool
    {
        return $this->linkedClone;
    }

    public function setLinkedClone(bool $linkedClone): static
    {
        $this->linkedClone = $linkedClone;

        return $this;
    }

    public function getNamePattern(): string
    {
        return $this->namePattern;
    }

    public function setNamePattern(string $namePattern): static
    {
        $this->namePattern = $namePattern;

        return $this;
    }

    public function getPostInstallScript(): ?string
    {
        return $this->postInstallScript;
    }

    public function setPostInstallScript(?string $postInstallScript): static
    {
        $this->postInstallScript = $postInstallScript;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRemindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }

    public function markReminded(): static
    {
        $this->remindedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Past its date. Says nothing about the machines - the application never destroys one. */
    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < new \DateTimeImmutable('today');
    }

    /** @return Collection<int, VmBatchItem> */
    /**
     * Whether a deployment is under way on this batch - which is what the screen reads to decide
     * whether to keep refreshing itself while somebody watches.
     *
     * Two conditions, and the second is what keeps the page alive **between** two machines: with
     * one machine deployed at a time there are moments when nothing is in flight at all and the
     * next has simply not been picked up yet, and a screen that stopped there would look finished
     * while the class was still being built.
     *
     * A batch whose remainder is nothing but refusals stops qualifying, and that is deliberate:
     * nothing will visibly move until somebody looks at it, and a page refreshing for ever over a
     * failure is noise rather than information.
     */
    public function isDeploymentUnderWay(): bool
    {
        $begun = false;
        $planned = false;

        foreach ($this->items as $item) {
            if (\in_array($item->getStatus(), [VmBatchItemStatus::Creating, VmBatchItemStatus::Created], true)) {
                return true;
            }

            if (VmBatchItemStatus::Planned === $item->getStatus()) {
                $planned = true;
            } else {
                $begun = true;
            }
        }

        return $begun && $planned;
    }

    /**
     * The machine a reader is most likely to be asking about: the first one that is not finished.
     *
     * Its installation log is the one the screen unfolds, so that arriving on a batch being
     * deployed shows what is happening rather than a row of closed triangles.
     */
    public function firstUnfinishedItem(): ?VmBatchItem
    {
        foreach ($this->items as $item) {
            if (VmBatchItemStatus::Provisioned !== $item->getStatus()) {
                return $item;
            }
        }

        return null;
    }

    /** @return Collection<int, VmBatchItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(VmBatchItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    /** @return Collection<int, GuestAccount> */
    public function getAccounts(): Collection
    {
        return $this->accounts;
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

    public function isActive(): bool
    {
        return null === $this->inactiveDate;
    }
}
