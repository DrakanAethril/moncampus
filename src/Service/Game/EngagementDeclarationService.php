<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EngagementDeclaration;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\EngagementKind;
use App\Repository\EngagementDeclarationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Filing a declared engagement, and the two answers it can get.
 *
 * **Nothing is credited before validation** - that is the whole point of the family: the six
 * automatic signals are worth little and capped by the week because they are repeatable at will,
 * and the bulk of volunteering is paid for things an adult has actually read.
 *
 * A refusal is motivated and read by the student, exactly like a malus, and the declaration stays in
 * the queue struck through rather than disappearing.
 */
final class EngagementDeclarationService
{
    private const string SOURCE = 'EngagementDeclaration';

    public function __construct(
        private readonly EngagementDeclarationRepository $declarations,
        private readonly GameLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws EngagementRefused when a once-per-period kind is declared a second time
     */
    public function file(User $student, Program $program, EngagementKind $kind, string $description): EngagementDeclaration
    {
        if ($kind->isOncePerPeriod() && $this->declarations->hasKindInProgram($student, $program, $kind)) {
            throw new EngagementRefused('engagementAlreadyDeclaredMessage');
        }

        $declaration = new EngagementDeclaration($student, $program, $kind);
        $declaration->setDescription(trim($description));

        $this->entityManager->persist($declaration);

        return $declaration;
    }

    /**
     * Validate: the points are written now, and the kind's own value is what they are.
     *
     * The reviewer never chooses an amount. A validator able to set the number would be able to
     * out-give the whole barème with one click, and the § 2 equity would leave through that door.
     */
    public function validate(EngagementDeclaration $declaration, User $reviewer): void
    {
        if ($declaration->getState()->isDecided()) {
            return;
        }

        $declaration->validate($reviewer);

        $this->ledger->record(
            $declaration->getStudent(),
            $declaration->getProgram(),
            $declaration->getKind()->ruleCode(),
            self::SOURCE,
            (int) $declaration->getId(),
            null,
            $declaration->points(),
            $reviewer,
        );

        $this->entityManager->flush();
    }

    /** Refuse, with a reason the student reads exactly as it was written. */
    public function refuse(EngagementDeclaration $declaration, User $reviewer, string $reason): void
    {
        if ($declaration->getState()->isDecided()) {
            return;
        }

        $reason = trim($reason);

        if ('' === $reason) {
            throw new EngagementRefused('engagementRefusalReasonRequiredMessage');
        }

        $declaration->refuse($reviewer, $reason);
        $this->entityManager->flush();
    }
}
