<?php

namespace App\Entity;

use App\Repository\EmailAliasRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une partie locale d'adresse (ce qui précède le `@`) rattachée à un élève. Le domaine n'est pas
 * stocké : il vient de MAIL_STUDENT_DOMAIN, ce qui laisse la même base fonctionner en dev
 * (`devetu.beaupeyrat.org`) et en production (`etu.beaupeyrat.org`).
 *
 * Table indispensable, et pas seulement pour du confort de nommage : la réception SES est en
 * catch-all, donc le worker reçoit *n'importe quelle* adresse du domaine et doit résoudre son
 * propriétaire par correspondance, jamais en devinant à partir du login.
 *
 * Plusieurs alias actifs par élève est le cas normal, pas l'exception :
 * - `prenom.nom` est l'adresse affichée et expéditrice ;
 * - le login LDAP (`croux`) est conservé en alias permanent, pour que les deux fonctionnent ;
 * - un changement d'état civil ajoute une adresse sans jamais retirer l'ancienne, qui reste
 *   imprimée sur des CV partis chez des entreprises et doit continuer à délivrer.
 *
 * Laquelle est « l'adresse » de l'élève - celle qu'on affiche et depuis laquelle on écrit - ne se
 * lit pas ici mais sur App\Entity\User::$primaryAlias. C'est un fait à valeur unique portant sur
 * l'élève, pas une propriété de chaque alias : le ranger côté User rend l'invariant « une seule
 * adresse principale » vrai par construction, là où un drapeau réparti sur N lignes aurait exigé
 * un index unique partiel que MySQL ne sait pas exprimer.
 */
#[ORM\Entity(repositoryClass: EmailAliasRepository::class)]
#[ORM\Table(name: 'email_alias')]
#[ORM\UniqueConstraint(name: 'uniq_email_alias_local_part', columns: ['local_part'])]
#[ORM\Index(name: 'idx_email_alias_user', columns: ['user_id'])]
class EmailAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 64 caractères : la limite de la partie locale fixée par la RFC 5321.
    #[ORM\Column(name: 'local_part', length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $localPart;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $user = null;

    /**
     * Une adresse désactivée ne sert plus à écrire, mais continue d'être résolue à la réception :
     * un mail arrivant sur une ancienne adresse doit rejoindre le bon élève, pas la file
     * « à rattacher ».
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function setLocalPart(string $localPart): static
    {
        $this->localPart = $localPart;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** L'adresse complète, le domaine venant de la configuration de l'environnement. */
    public function toAddress(string $domain): string
    {
        return $this->localPart.'@'.$domain;
    }
}
