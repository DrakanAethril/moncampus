<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EnterpriseSiretStatus;
use App\Repository\EnterpriseRepository;
use App\Service\Sirene\Siret;
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

    /**
     * How long « Pas de SIRET trouvable » keeps an employer out of the SIRET queue - long enough
     * for a company that has only just been created to reach SIRENE, short enough for it to be
     * looked at again within the school year (design/validated/siret-entreprises.md, R7 and §9).
     * The one place the delay is written: no expiry date is stored, it is computed on reading.
     */
    public const int SIRET_SET_ASIDE_DAYS = 90;

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

    /** Stored as its fourteen digits, whatever spacing it was typed with (Siret::normalize()). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siret = null;

    /**
     * When a person associated or confirmed the SIRET while seeing what it designates - the only way
     * one becomes « confirmé » (R4). Null for every number imported, typed without a preview, or
     * recorded before the rule existed: those are « à confirmer ». Nothing automatic ever sets it.
     */
    #[ORM\Column(name: 'siret_confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $siretConfirmedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'siret_confirmed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $siretConfirmedBy = null;

    /**
     * « Pas de SIRET trouvable » - a dated set-aside, not a verdict: the employer leaves the queue and
     * comes back SIRET_SET_ASIDE_DAYS later (R7). It never empties a SIRET already recorded.
     */
    #[ORM\Column(name: 'siret_not_found_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $siretNotFoundAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'siret_not_found_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $siretNotFoundBy = null;

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

    /**
     * A SIRET recorded without anyone vouching for it - the « nouvelle entreprise » of an alternance
     * (R5), a form that saves before stamping. A different number is a new question: whatever was
     * confirmed or set aside about the old one no longer holds.
     */
    public function setSiret(?string $siret): static
    {
        $siret = null !== $siret ? Siret::normalize($siret) : null;
        $siret = '' !== $siret ? $siret : null;

        if ($siret !== $this->siret) {
            $this->siretConfirmedAt = null;
            $this->siretConfirmedBy = null;
            $this->siretNotFoundAt = null;
            $this->siretNotFoundBy = null;
        }

        $this->siret = $siret;

        return $this;
    }

    /** « Associer » or « Confirmer »: a person saw what this number designates and said yes (R4). */
    public function confirmSiret(string $siret, User $by, \DateTimeImmutable $at): static
    {
        $this->setSiret($siret);
        $this->siretConfirmedAt = $at;
        $this->siretConfirmedBy = $by;
        $this->siretNotFoundAt = null;
        $this->siretNotFoundBy = null;

        return $this;
    }

    /** « Pas de SIRET trouvable » - out of the queue for SIRET_SET_ASIDE_DAYS, SIRET left as it is (R7). */
    public function markSiretNotFound(User $by, \DateTimeImmutable $at): static
    {
        $this->siretNotFoundAt = $at;
        $this->siretNotFoundBy = $by;

        return $this;
    }

    public function getSiretConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->siretConfirmedAt;
    }

    public function getSiretConfirmedBy(): ?User
    {
        return $this->siretConfirmedBy;
    }

    public function getSiretNotFoundAt(): ?\DateTimeImmutable
    {
        return $this->siretNotFoundAt;
    }

    public function getSiretNotFoundBy(): ?User
    {
        return $this->siretNotFoundBy;
    }

    /** The day a « Pas de SIRET trouvable » runs out, or null when there is none. */
    public function getSiretSetAsideUntil(): ?\DateTimeImmutable
    {
        return $this->siretNotFoundAt?->modify(\sprintf('+%d days', self::SIRET_SET_ASIDE_DAYS));
    }

    /**
     * The same reading EnterpriseRepository::queryPendingSiret() makes in SQL - the two must agree,
     * and EnterpriseSiretStatusTest holds them to it.
     */
    public function siretStatus(\DateTimeImmutable $now): EnterpriseSiretStatus
    {
        if (null !== $this->siretConfirmedAt) {
            return EnterpriseSiretStatus::Confirmed;
        }

        $until = $this->getSiretSetAsideUntil();
        if (null !== $until && $until > $now) {
            return EnterpriseSiretStatus::SetAside;
        }

        return null !== $this->siret ? EnterpriseSiretStatus::Pending : EnterpriseSiretStatus::Missing;
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
