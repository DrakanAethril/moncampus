<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessConditionDisplay;
use App\Service\AccessConditionTree;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The two columns an access condition needs, mixed into the four hosts.
 *
 * The condition itself is a JSON column and null means "no condition", so nothing existing moves
 * and the migration rewrites no row - the same stance blanks_config/zoneConfig/matchingConfig take,
 * typed at the boundary by AccessConditionTree rather than read raw.
 *
 * The display is a column of its own because it is a teacher's choice and not a deduction: see
 * AccessConditionDisplay for the counter-example that settles it.
 */
trait AccessConditionTrait
{
    /**
     * Never read raw outside getAccessConditionTree(): the stored shape is
     * {"all"|"any": [{"type": …}, …]}, and AccessConditionTree owns it.
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(name: 'access_condition', type: Types::JSON, nullable: true)]
    private ?array $accessCondition = null;

    #[ORM\Column(name: 'access_condition_display', length: 20, enumType: AccessConditionDisplay::class, options: ['default' => 'locked'])]
    private AccessConditionDisplay $accessConditionDisplay = AccessConditionDisplay::Locked;

    public function getAccessConditionTree(): ?AccessConditionTree
    {
        return AccessConditionTree::fromArray($this->accessCondition);
    }

    public function setAccessConditionTree(?AccessConditionTree $tree): static
    {
        $this->accessCondition = $tree?->toArray();

        return $this;
    }

    public function hasAccessCondition(): bool
    {
        return null !== $this->getAccessConditionTree();
    }

    public function getAccessConditionDisplay(): AccessConditionDisplay
    {
        return $this->accessConditionDisplay;
    }

    public function setAccessConditionDisplay(AccessConditionDisplay $display): static
    {
        $this->accessConditionDisplay = $display;

        return $this;
    }
}
