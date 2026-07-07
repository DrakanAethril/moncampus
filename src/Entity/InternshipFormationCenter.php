<?php

namespace App\Entity;

use App\Repository\InternshipFormationCenterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Establishment-wide contact info shown on the Livret Alternant booklet's "centre de
 * formation" page (director / campus director / alternance manager / handicap referent) - a
 * singleton settings row (no inactiveDate/deactivate lifecycle - there's exactly one per this
 * single-tenant installation), unlike the reference app's Establishment.formationCenter JSON
 * blob, flattened here into typed columns to match this project's entity conventions.
 */
#[ORM\Entity(repositoryClass: InternshipFormationCenterRepository::class)]
#[ORM\Table(name: 'internship_formation_center')]
class InternshipFormationCenter
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $generalInfo = null;

    #[ORM\Column(name: 'director_first_name', length: 255, nullable: true)]
    private ?string $directorFirstName = null;

    #[ORM\Column(name: 'director_last_name', length: 255, nullable: true)]
    private ?string $directorLastName = null;

    #[ORM\Column(name: 'director_email', length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $directorEmail = null;

    #[ORM\Column(name: 'director_phone', length: 30, nullable: true)]
    private ?string $directorPhone = null;

    #[ORM\Column(name: 'campus_director_first_name', length: 255, nullable: true)]
    private ?string $campusDirectorFirstName = null;

    #[ORM\Column(name: 'campus_director_last_name', length: 255, nullable: true)]
    private ?string $campusDirectorLastName = null;

    #[ORM\Column(name: 'campus_director_email', length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $campusDirectorEmail = null;

    #[ORM\Column(name: 'campus_director_phone', length: 30, nullable: true)]
    private ?string $campusDirectorPhone = null;

    #[ORM\Column(name: 'alternance_manager_first_name', length: 255, nullable: true)]
    private ?string $alternanceManagerFirstName = null;

    #[ORM\Column(name: 'alternance_manager_last_name', length: 255, nullable: true)]
    private ?string $alternanceManagerLastName = null;

    #[ORM\Column(name: 'alternance_manager_email', length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $alternanceManagerEmail = null;

    #[ORM\Column(name: 'alternance_manager_phone', length: 30, nullable: true)]
    private ?string $alternanceManagerPhone = null;

    #[ORM\Column(name: 'handicap_referent_first_name', length: 255, nullable: true)]
    private ?string $handicapReferentFirstName = null;

    #[ORM\Column(name: 'handicap_referent_last_name', length: 255, nullable: true)]
    private ?string $handicapReferentLastName = null;

    #[ORM\Column(name: 'handicap_referent_email', length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $handicapReferentEmail = null;

    #[ORM\Column(name: 'handicap_referent_phone', length: 30, nullable: true)]
    private ?string $handicapReferentPhone = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGeneralInfo(): ?string
    {
        return $this->generalInfo;
    }

    public function setGeneralInfo(?string $generalInfo): static
    {
        $this->generalInfo = $generalInfo;

        return $this;
    }

    public function getDirectorFirstName(): ?string
    {
        return $this->directorFirstName;
    }

    public function setDirectorFirstName(?string $directorFirstName): static
    {
        $this->directorFirstName = $directorFirstName;

        return $this;
    }

    public function getDirectorLastName(): ?string
    {
        return $this->directorLastName;
    }

    public function setDirectorLastName(?string $directorLastName): static
    {
        $this->directorLastName = $directorLastName;

        return $this;
    }

    public function getDirectorEmail(): ?string
    {
        return $this->directorEmail;
    }

    public function setDirectorEmail(?string $directorEmail): static
    {
        $this->directorEmail = $directorEmail;

        return $this;
    }

    public function getDirectorPhone(): ?string
    {
        return $this->directorPhone;
    }

    public function setDirectorPhone(?string $directorPhone): static
    {
        $this->directorPhone = $directorPhone;

        return $this;
    }

    public function getCampusDirectorFirstName(): ?string
    {
        return $this->campusDirectorFirstName;
    }

    public function setCampusDirectorFirstName(?string $campusDirectorFirstName): static
    {
        $this->campusDirectorFirstName = $campusDirectorFirstName;

        return $this;
    }

    public function getCampusDirectorLastName(): ?string
    {
        return $this->campusDirectorLastName;
    }

    public function setCampusDirectorLastName(?string $campusDirectorLastName): static
    {
        $this->campusDirectorLastName = $campusDirectorLastName;

        return $this;
    }

    public function getCampusDirectorEmail(): ?string
    {
        return $this->campusDirectorEmail;
    }

    public function setCampusDirectorEmail(?string $campusDirectorEmail): static
    {
        $this->campusDirectorEmail = $campusDirectorEmail;

        return $this;
    }

    public function getCampusDirectorPhone(): ?string
    {
        return $this->campusDirectorPhone;
    }

    public function setCampusDirectorPhone(?string $campusDirectorPhone): static
    {
        $this->campusDirectorPhone = $campusDirectorPhone;

        return $this;
    }

    public function getAlternanceManagerFirstName(): ?string
    {
        return $this->alternanceManagerFirstName;
    }

    public function setAlternanceManagerFirstName(?string $alternanceManagerFirstName): static
    {
        $this->alternanceManagerFirstName = $alternanceManagerFirstName;

        return $this;
    }

    public function getAlternanceManagerLastName(): ?string
    {
        return $this->alternanceManagerLastName;
    }

    public function setAlternanceManagerLastName(?string $alternanceManagerLastName): static
    {
        $this->alternanceManagerLastName = $alternanceManagerLastName;

        return $this;
    }

    public function getAlternanceManagerEmail(): ?string
    {
        return $this->alternanceManagerEmail;
    }

    public function setAlternanceManagerEmail(?string $alternanceManagerEmail): static
    {
        $this->alternanceManagerEmail = $alternanceManagerEmail;

        return $this;
    }

    public function getAlternanceManagerPhone(): ?string
    {
        return $this->alternanceManagerPhone;
    }

    public function setAlternanceManagerPhone(?string $alternanceManagerPhone): static
    {
        $this->alternanceManagerPhone = $alternanceManagerPhone;

        return $this;
    }

    public function getHandicapReferentFirstName(): ?string
    {
        return $this->handicapReferentFirstName;
    }

    public function setHandicapReferentFirstName(?string $handicapReferentFirstName): static
    {
        $this->handicapReferentFirstName = $handicapReferentFirstName;

        return $this;
    }

    public function getHandicapReferentLastName(): ?string
    {
        return $this->handicapReferentLastName;
    }

    public function setHandicapReferentLastName(?string $handicapReferentLastName): static
    {
        $this->handicapReferentLastName = $handicapReferentLastName;

        return $this;
    }

    public function getHandicapReferentEmail(): ?string
    {
        return $this->handicapReferentEmail;
    }

    public function setHandicapReferentEmail(?string $handicapReferentEmail): static
    {
        $this->handicapReferentEmail = $handicapReferentEmail;

        return $this;
    }

    public function getHandicapReferentPhone(): ?string
    {
        return $this->handicapReferentPhone;
    }

    public function setHandicapReferentPhone(?string $handicapReferentPhone): static
    {
        $this->handicapReferentPhone = $handicapReferentPhone;

        return $this;
    }
}
