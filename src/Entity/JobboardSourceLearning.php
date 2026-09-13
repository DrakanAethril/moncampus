<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobboardLearningKind;
use App\Repository\JobboardSourceLearningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing a deposit of offers taught the platform about the sites it collects from.
 *
 * The list of sites is open: an offer from a site nobody declared is filed rather than refused, and
 * a known site met on an unknown host gets that host attached to it
 * (App\Service\Jobboard\JobboardSourceResolver). Both are the right behaviour - the alternative was
 * losing offers - and both are **silent**, which is what this table is for.
 *
 * **It is a record, not a control.** Nothing reads these rows to decide anything; they exist so an
 * administrator can see that the veille invented a site or glued a domain onto an existing one, and
 * undo it on the sources screen if it was wrong. The case that needs the screen is the second one:
 * a redirector or a link shortener declared under a known name attaches an alien domain to that
 * site, and from then on every offer from behind that domain files there without a word.
 *
 * A row always belongs to a deposit - the lot is what makes it attributable, and the whole point is
 * being able to say *when* and *through which pass* a domain arrived. A site created by a cursor
 * write belongs to no deposit and is deliberately not recorded here: it carries no domain, and the
 * sources screen already says which rows the veille brought.
 */
#[ORM\Entity(repositoryClass: JobboardSourceLearningRepository::class)]
#[ORM\Table(name: 'jobboard_source_learning')]
#[ORM\Index(name: 'idx_jobboard_learning_at', columns: ['learned_at'])]
class JobboardSourceLearning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: JobboardBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false, onDelete: 'CASCADE')]
    private JobboardBatch $batch;

    #[ORM\ManyToOne(targetEntity: JobboardSource::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false, onDelete: 'CASCADE')]
    private JobboardSource $source;

    #[ORM\Column(length: 16, enumType: JobboardLearningKind::class)]
    private JobboardLearningKind $kind;

    // The name the agent declared on the offer that caused this. Kept even when the resolution
    // ignored it: reading « déclaré hellowork, domaine bit.ly » is what makes a wrong attachment
    // obvious, and the resolved source alone would not show it.
    #[ORM\Column(length: 255)]
    private string $declared;

    #[ORM\Column(length: 190)]
    private string $domain;

    #[ORM\Column(name: 'learned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $learnedAt;

    public function __construct(
        JobboardBatch $batch,
        JobboardSource $source,
        JobboardLearningKind $kind,
        string $declared,
        string $domain,
        ?\DateTimeImmutable $learnedAt = null,
    ) {
        $this->batch = $batch;
        $this->source = $source;
        $this->kind = $kind;
        $this->declared = mb_substr($declared, 0, 255);
        $this->domain = mb_substr($domain, 0, 190);
        $this->learnedAt = $learnedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): JobboardBatch
    {
        return $this->batch;
    }

    public function getSource(): JobboardSource
    {
        return $this->source;
    }

    public function getKind(): JobboardLearningKind
    {
        return $this->kind;
    }

    public function getDeclared(): string
    {
        return $this->declared;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getLearnedAt(): \DateTimeImmutable
    {
        return $this->learnedAt;
    }
}
