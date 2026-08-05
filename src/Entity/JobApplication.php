<?php

namespace App\Entity;

use App\Enum\JobApplicationOrigin;
use App\Repository\JobApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une démarche de candidature d'un élève auprès d'une entreprise
 * (design_handoff_stage_alternance, écrans 2a et 2b : « Candidatures et démarches », groupées
 * par entreprise).
 *
 * Le regroupement se fait par entreprise et non par mail : un envoi, sa relance et la réponse
 * reçue appartiennent à la même démarche. C'est la démarche qui porte le contexte (poste visé,
 * contact), les App\Entity\EmailMessage n'en sont que les traces.
 *
 * **Aucun statut d'avancement.** Le handoff l'interdit explicitement (principe n°1) : la
 * plateforme rassemble les mails, elle ne les classe pas. Pas de « proposition », pas de
 * « refus », pas d'« entretien ». Ce qui s'affiche à l'écran (« délivrée, sans réponse »,
 * « Réponse reçue le 15/09 ») se **déduit** des mails et de leurs événements SES, et n'est jamais
 * stocké comme un jugement.
 */
#[ORM\Entity(repositoryClass: JobApplicationRepository::class)]
#[ORM\Table(name: 'job_application')]
#[ORM\Index(name: 'idx_job_application_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_job_application_enterprise', columns: ['enterprise_id'])]
class JobApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false)]
    #[Assert\NotNull]
    private ?Enterprise $enterprise = null;

    /** Le poste visé, tel que l'élève le formule (« Développeur web (alternance) »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $position = null;

    /** Le contact chez l'entreprise, affiché à côté du poste sur la fiche enseignant. */
    #[ORM\Column(name: 'contact_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $contactName = null;

    #[ORM\Column(length: 20, enumType: JobApplicationOrigin::class)]
    private JobApplicationOrigin $origin = JobApplicationOrigin::Spontaneous;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, EmailMessage>
     *
     * Envois, relances et réponses de cette démarche, dans les deux sens. Les réponses entrantes
     * y arrivent sans qu'aucune question ne soit posée : elles héritent de la démarche de l'envoi
     * auquel elles répondent, par In-Reply-To/References (principe n°5 du handoff).
     */
    #[ORM\OneToMany(mappedBy: 'jobApplication', targetEntity: EmailMessage::class)]
    #[ORM\OrderBy(['messageDate' => 'ASC'])]
    private Collection $emailMessages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->emailMessages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function setEnterprise(?Enterprise $enterprise): static
    {
        $this->enterprise = $enterprise;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getOrigin(): JobApplicationOrigin
    {
        return $this->origin;
    }

    public function setOrigin(JobApplicationOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, EmailMessage> */
    public function getEmailMessages(): Collection
    {
        return $this->emailMessages;
    }

    /** Le dernier mouvement de la démarche, dans un sens ou dans l'autre - sert au tri de 2a. */
    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        $last = null;

        foreach ($this->emailMessages as $message) {
            $date = $message->getMessageDate() ?? $message->getCreatedAt();

            if (null === $last || $date > $last) {
                $last = $date;
            }
        }

        return $last;
    }

    /** Une réponse est simplement un message entrant : aucun contenu n'est interprété. */
    public function hasReply(): bool
    {
        foreach ($this->emailMessages as $message) {
            if (\App\Enum\EmailDirection::Inbound === $message->getDirection()) {
                return true;
            }
        }

        return false;
    }
}
