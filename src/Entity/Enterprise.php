<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EnterpriseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reusable employer/company record - first introduced for InternshipTutorLink (an entreprise
 * tutor's employer), kept standalone rather than inlined there so the same Enterprise can be
 * picked again for a future student/contract instead of retyping name/address every time.
 */
#[ORM\Entity(repositoryClass: EnterpriseRepository::class)]
#[ORM\Table(name: 'enterprise')]
class Enterprise
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    /**
     * City, entered when creating a company from screen 3g (optional, unlike the name). Distinct from
     * $address, which is the full postal address of the UFA module.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    /**
     * The company's mail domain (`neopixel.fr`), without an at sign or a service subdomain.
     *
     * This is the key to the second linking case (screen 3g): an unknown address on an already
     * known domain suggests the matching company. Never filled in for a generic domain (gmail,
     * orange...), where linking happens on the full address - otherwise every individual on the
     * same provider would become the same company.
     */
    #[ORM\Column(name: 'email_domain', length: 255, nullable: true)]
    private ?string $emailDomain = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    // Set only by "créer une alternance de test" (see App\Form\InternshipAlternanceType), and only
    // on an Enterprise that submission itself created - picking an existing employer for a test
    // alternance never turns that real company into a fake one. Same asymmetry as
    // Program::$testProgram: a test account only ever sees these, a real one keeps seeing all.
    #[ORM\Column(name: 'test_enterprise', options: ['default' => false])]
    private bool $testEnterprise = false;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, ?string $address = null)
    {
        $this->name = $name;
        $this->address = $address;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getEmailDomain(): ?string
    {
        return $this->emailDomain;
    }

    public function setEmailDomain(?string $emailDomain): static
    {
        $this->emailDomain = null !== $emailDomain ? mb_strtolower(trim($emailDomain)) : null;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function isTestEnterprise(): bool
    {
        return $this->testEnterprise;
    }

    public function setTestEnterprise(bool $testEnterprise): static
    {
        $this->testEnterprise = $testEnterprise;

        return $this;
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
}
