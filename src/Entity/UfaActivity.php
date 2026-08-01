<?php

namespace App\Entity;

use App\Enum\UfaActivityType;
use App\Repository\UfaActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal horodaté des actions de l'UFA - signatures du livret, relances - destiné aux écrans de
 * suivi des derniers événements.
 *
 * En ajout seul : une ligne n'est jamais modifiée ni supprimée, et rien dans l'application ne s'en
 * sert comme source de vérité. C'est ce qui autorise à y écrire APRÈS le flush métier, sans
 * transaction commune : un journal ne doit pas pouvoir faire échouer l'action qu'il observe.
 *
 * $payload garde l'instantané des noms au moment des faits. Les clés étrangères servent à filtrer
 * et à naviguer, le payload à rendre la phrase - une alternance désactivée, un compte renommé ou
 * une période supprimée laissent ainsi un historique encore lisible.
 *
 * Pas de purge ici, contrairement à App\Entity\PlatformActivity : le volume est faible (quelques
 * lignes par alternance et par période) et ces événements sont l'histoire d'un livret.
 */
#[ORM\Entity(repositoryClass: UfaActivityRepository::class)]
#[ORM\Table(name: 'ufa_activity')]
#[ORM\Index(name: 'idx_ufa_activity_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_ufa_activity_tutor_link', columns: ['tutor_link_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_ufa_activity_program', columns: ['program_id', 'occurred_at'])]
class UfaActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 60, enumType: UfaActivityType::class)]
    private UfaActivityType $type;

    // Null pour ce qu'aucun humain n'a déclenché - rien aujourd'hui, mais une relance automatique
    // est prévue de longue date (voir InternshipReminder::$auto).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipTutorLink $tutorLink = null;

    // Null pour les 3 signatures d'engagement, qui ne portent sur aucune période.
    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    // Dénormalisé depuis l'alternance : les écrans UFA filtrent par formation, et le faire par
    // jointure sur tutor_link interdirait de garder la trace après désactivation de l'alternance.
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true, onDelete: 'SET NULL')]
    private ?Program $program = null;

    // Recopié de InternshipTutorLink::$testAlternance : sans lui, un écran de suivi mélangerait
    // les deux mondes que le reste de l'UFA sépare strictement.
    #[ORM\Column(name: 'test_data', options: ['default' => false])]
    private bool $testData = false;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** @param array<string, string> $payload */
    public function __construct(UfaActivityType $type, ?User $actor, array $payload = [])
    {
        $this->type = $type;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): UfaActivityType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getTutorLink(): ?InternshipTutorLink
    {
        return $this->tutorLink;
    }

    public function setTutorLink(?InternshipTutorLink $tutorLink): static
    {
        $this->tutorLink = $tutorLink;

        return $this;
    }

    public function getEvaluationPeriod(): ?InternshipEvaluationPeriod
    {
        return $this->evaluationPeriod;
    }

    public function setEvaluationPeriod(?InternshipEvaluationPeriod $evaluationPeriod): static
    {
        $this->evaluationPeriod = $evaluationPeriod;

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

    public function isTestData(): bool
    {
        return $this->testData;
    }

    public function setTestData(bool $testData): static
    {
        $this->testData = $testData;

        return $this;
    }

    /** @return array<string, string> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
