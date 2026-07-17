<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One "sortie d'app" (background) event for a runner - $returnedAt/$durationSeconds stay null
// while still backgrounded (mirrored live on EcoRunner::$appLeftAt) and are filled in together
// once the app comes back to the foreground and reports the return.
#[ORM\Entity(repositoryClass: \App\Repository\EcoAppEventRepository::class)]
#[ORM\Table(name: 'eco_app_event')]
class EcoAppEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoRunner::class)]
    #[ORM\JoinColumn(name: 'runner_id', nullable: false)]
    private ?EcoRunner $runner = null;

    #[ORM\Column(name: 'left_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $leftAt = null;

    #[ORM\Column(name: 'returned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(name: 'duration_seconds', nullable: true)]
    private ?int $durationSeconds = null;

    public function __construct(EcoRunner $runner, \DateTimeImmutable $leftAt)
    {
        $this->runner = $runner;
        $this->leftAt = $leftAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunner(): ?EcoRunner
    {
        return $this->runner;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function markReturned(\DateTimeImmutable $returnedAt): static
    {
        $this->returnedAt = $returnedAt;
        $this->durationSeconds = $returnedAt->getTimestamp() - $this->leftAt->getTimestamp();

        return $this;
    }
}
