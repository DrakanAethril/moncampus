<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchoolYearImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One school-year import (design/validated/school-year-import.md, R11): who copied which year's
 * configuration into which, with what ticked, and what came of it. Written once, by
 * App\Service\SchoolYearImport\SchoolYearImporter, in the transaction that wrote the copies -
 * an import that failed leaves no journal either. Read by the « Compte rendu » screen and by the
 * trace on the school-years list.
 *
 * @phpstan-import-type ImportChoices from \App\Service\SchoolYearImport\SchoolYearImportChoices
 * @phpstan-import-type ImportReport from \App\Service\SchoolYearImport\SchoolYearImportReport
 */
#[ORM\Entity(repositoryClass: SchoolYearImportRepository::class)]
#[ORM\Table(name: 'school_year_import')]
class SchoolYearImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false)]
    private SchoolYear $source;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class)]
    #[ORM\JoinColumn(name: 'target_id', nullable: false)]
    private SchoolYear $target;

    /** @var ImportChoices */
    #[ORM\Column(type: Types::JSON)]
    private array $choices;

    /** @var ImportReport */
    #[ORM\Column(type: Types::JSON)]
    private array $report;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /**
     * @param ImportChoices $choices
     * @param ImportReport  $report
     */
    public function __construct(SchoolYear $source, SchoolYear $target, array $choices, array $report, ?User $createdBy, \DateTimeImmutable $creationDate)
    {
        $this->source = $source;
        $this->target = $target;
        $this->choices = $choices;
        $this->report = $report;
        $this->createdBy = $createdBy;
        $this->creationDate = $creationDate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): SchoolYear
    {
        return $this->source;
    }

    public function getTarget(): SchoolYear
    {
        return $this->target;
    }

    /** @return ImportChoices */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /** @return ImportReport */
    public function getReport(): array
    {
        return $this->report;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
