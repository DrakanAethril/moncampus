<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContractTypeCode;
use App\Repository\ContractTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the two fixed UFA contract types (apprentissage / professionnalisation - see
 * ContractTypeCode) and its center-level default modalities (HugeRTE HTML), shown on
 * "Configuration > Modalités de contrats" (22a). Fixed set, no create/rename/deactivate UI -
 * unlike LaptopConditionType/InternshipBehaviorCriteria, ContractTypeCode::cases() is the list,
 * not a DB query. A Program overrides these defaults via ProgramContractModality (presence of a
 * row IS the override, same "hérité/surchargé" convention as InternshipOptionLegalName). Lazily
 * created on first edit (find-or-new), same singleton pattern as InternshipFormationCenter,
 * rather than migration-seeded - avoids needing a dummy created-by user for rows nobody has
 * edited yet.
 */
#[ORM\Entity(repositoryClass: ContractTypeRepository::class)]
#[ORM\Table(name: 'contract_type')]
class ContractType
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true, enumType: ContractTypeCode::class)]
    private ContractTypeCode $code;

    #[ORM\Column(name: 'default_modalities_html', type: Types::TEXT, nullable: true)]
    private ?string $defaultModalitiesHtml = null;

    public function __construct(ContractTypeCode $code)
    {
        $this->code = $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ContractTypeCode
    {
        return $this->code;
    }

    public function getDefaultModalitiesHtml(): ?string
    {
        return $this->defaultModalitiesHtml;
    }

    public function setDefaultModalitiesHtml(?string $defaultModalitiesHtml): static
    {
        $this->defaultModalitiesHtml = $defaultModalitiesHtml;

        return $this;
    }
}
