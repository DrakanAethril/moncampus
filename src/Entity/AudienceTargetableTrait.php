<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageAudienceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The audience *set* every App\Entity\AudienceTargetable carries, and the only part of that
 * interface identical enough across MessageThread/Announcement/AgendaEvent/SignupList to be worth
 * sharing (their $programs/$manualRecipients join tables are per-entity, so those stay in each
 * class).
 *
 * Stored as a `simple_array` rather than one row per type: the set has at most five members drawn
 * from a closed enum, it is always read whole with its owner, and nothing joins or aggregates on
 * it - a join table would buy nothing and cost a query. The column is nullable because that is how
 * Doctrine's simple_array represents the empty set (it writes NULL rather than an empty string,
 * which would otherwise read back as [''] - see Doctrine\DBAL\Types\SimpleArrayType); the "at
 * least one audience" rule is held by the Assert\Count below, exactly as the older single
 * audience_type column held it with Assert\NotNull.
 */
trait AudienceTargetableTrait
{
    /**
     * Enum *values*, not cases: simple_array stores strings. Kept sorted into
     * MessageAudienceType's declaration order by setAudienceTypes() - see MessageAudienceType::
     * sort() for the two things that depend on it.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'audience_types', type: Types::SIMPLE_ARRAY, nullable: true)]
    #[Assert\Count(min: 1, minMessage: 'messageAudienceTypesRequiredError')]
    private array $audienceTypes = [];

    /** @return list<MessageAudienceType> */
    public function getAudienceTypes(): array
    {
        // tryFrom rather than from: a value written by an older release that has since been
        // dropped from the enum must not make the whole row unreadable.
        return array_values(array_filter(array_map(
            static fn (string $value): ?MessageAudienceType => MessageAudienceType::tryFrom($value),
            $this->audienceTypes,
        )));
    }

    public function hasAudienceType(MessageAudienceType $type): bool
    {
        return \in_array($type->value, $this->audienceTypes, true);
    }

    /**
     * Deliberately the only mutator - no addAudienceType()/removeAudienceType() pair, because
     * Symfony's PropertyAccess would then prefer it over this setter when binding the form's
     * `audienceTypes` checkboxes, and the sort below would never run.
     *
     * @param list<MessageAudienceType> $types
     */
    public function setAudienceTypes(array $types): static
    {
        $this->audienceTypes = array_values(array_unique(array_map(
            static fn (MessageAudienceType $type): string => $type->value,
            MessageAudienceType::sort($types),
        )));

        return $this;
    }
}
