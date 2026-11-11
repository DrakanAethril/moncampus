<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CrmSchoolYearRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Formatool school year as the CRM sees it: opened in the CRM or not, and the default one -
 * the year everybody lands on. The CRM creates no year: it opens the ones Formatool has.
 *
 * The default year is always an opened one, and cannot be closed while it is the default
 * (App\Service\Crm\CrmSettings holds both rules).
 */
#[ORM\Entity(repositoryClass: CrmSchoolYearRepository::class)]
#[ORM\Table(name: 'crm_school_year')]
#[ORM\UniqueConstraint(name: 'uniq_crm_school_year', columns: ['school_year_id'])]
class CrmSchoolYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolYear $schoolYear;

    #[ORM\Column]
    private bool $opened = false;

    #[ORM\Column(name: 'is_default')]
    private bool $default = false;

    public function __construct(SchoolYear $schoolYear)
    {
        $this->schoolYear = $schoolYear;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function isOpened(): bool
    {
        return $this->opened;
    }

    public function setOpened(bool $opened): void
    {
        $this->opened = $opened;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function setDefault(bool $default): void
    {
        $this->default = $default;
    }
}
