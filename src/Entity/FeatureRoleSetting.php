<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Feature;
use App\Repository\FeatureRoleSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One cell of the matrix drawn by Paramètres > Fonctionnalités: is this feature on for this role
 * (design/validated/feature-access.md §5).
 *
 * Two things it deliberately is not:
 *
 * - **a row for `ROLE_ADMIN`.** An admin has everything without reading anything, so a column here
 *   would only ever be a way to lock the establishment out of its own settings screen.
 * - **exhaustive.** A missing pair is not a `false`: App\Security\FeatureResolver falls back on
 *   Feature::defaultForRoles(), which is what makes adding a feature - or adding a role to
 *   Feature::managedRoles() - a code change with no data migration behind it.
 *
 * No AuditableTrait: this is not a structure node. What matters about a change here is that it
 * happened, and that is recorded as a PlatformActivity like the other account-administration
 * gestures.
 */
#[ORM\Entity(repositoryClass: FeatureRoleSettingRepository::class)]
#[ORM\Table(name: 'feature_role_setting')]
#[ORM\UniqueConstraint(name: 'uniq_feature_role', columns: ['feature', 'role'])]
class FeatureRoleSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, enumType: Feature::class)]
    private Feature $feature;

    #[ORM\Column(name: '`role`', length: 64)]
    private string $role;

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(Feature $feature, string $role, bool $enabled)
    {
        $this->feature = $feature;
        $this->role = $role;
        $this->enabled = $enabled;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeature(): Feature
    {
        return $this->feature;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
