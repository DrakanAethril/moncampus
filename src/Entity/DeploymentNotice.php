<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeploymentOutcome;
use App\Repository\DeploymentNoticeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One production deployment, from the moment it was announced to the moment it was over - and the
 * banner every visitor sees in between.
 *
 * A table and not a file or a cache entry, for the reason that decides this feature entirely: the
 * deploy **replaces the container**. Anything the announcement writes into the application's own
 * memory or filesystem is gone by the time the new version answers, and the notice has to be
 * readable on both sides of that restart - it is raised by the version being replaced and lowered
 * by the version replacing it. The database is the only thing standing on both banks.
 *
 * $expiresAt is not a detail. The notice is closed by a second HTTP call, and a workflow that dies
 * between the two calls - a cancelled run, a runner that vanishes - would otherwise leave a banner
 * announcing a restart that is never coming, on every screen, for ever. Past that instant the
 * notice simply stops being current, whatever was or was not announced.
 */
#[ORM\Entity(repositoryClass: DeploymentNoticeRepository::class)]
#[ORM\Table(name: 'deployment_notice')]
#[ORM\Index(name: 'idx_deployment_notice_open', columns: ['finished_at', 'expires_at'])]
class DeploymentNotice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'outcome', length: 20, enumType: DeploymentOutcome::class, nullable: true)]
    private ?DeploymentOutcome $outcome = null;

    /**
     * The release this deployment carries, as config/changelog.yaml names it - « 2026.08.28 ».
     * Nullable because the announcement is allowed to fail at reading it and must still announce:
     * a banner that says a restart is coming is worth more than one that names the version.
     */
    #[ORM\Column(name: 'version', length: 32, nullable: true)]
    private ?string $version = null;

    public function __construct(\DateTimeImmutable $startedAt, \DateTimeImmutable $expiresAt, ?string $version = null)
    {
        $this->startedAt = $startedAt;
        $this->expiresAt = $expiresAt;
        $this->version = $version;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getOutcome(): ?DeploymentOutcome
    {
        return $this->outcome;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function finish(\DateTimeImmutable $at, DeploymentOutcome $outcome): static
    {
        $this->finishedAt = $at;
        $this->outcome = $outcome;

        return $this;
    }

    /** Is this notice the one a visitor should be seeing right now? */
    public function isOpenAt(\DateTimeImmutable $now): bool
    {
        return null === $this->finishedAt && $this->expiresAt > $now;
    }
}
