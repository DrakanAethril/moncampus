<?php

namespace App\Service;

use App\Entity\AudienceTargetable;
use App\Enum\MessageAudienceType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders an AudienceTargetable's audience as a short, human-readable string (e.g. "Programme(s)
 * — SIO1, SIO2" or "Tous les personnels") - the same information the web's audience-picker labels
 * already carry (see MessageAudienceType::labelKey(), announcement/index.html.twig's inline badge
 * markup), just as plain text for JSON API responses (Api\AgendaController, Api\
 * AnnouncementController) instead of HTML. Manual is deliberately rendered as its bare label
 * ("Choisir des destinataires" reads oddly standalone, but resolving the actual recipient list
 * here would mean injecting AudienceResolver/a repository into a pure formatting service for a
 * label mobile treats as informational only - not worth the coupling for this).
 */
class AudienceLabelFormatter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function format(AudienceTargetable $target): string
    {
        $audienceType = $target->getAudienceType();
        if (null === $audienceType) {
            return '';
        }

        $label = $this->translator->trans($audienceType->labelKey());

        if (MessageAudienceType::Program !== $audienceType) {
            return $label;
        }

        $programNames = array_map(
            static fn ($program): string => $program->getShortName(),
            $target->getPrograms()->toArray(),
        );

        $roleLabel = match (true) {
            $target->isIncludeStudents() && $target->isIncludeTeachers() => $this->translator->trans('messageAudienceRoleBothLabel'),
            $target->isIncludeTeachers() => $this->translator->trans('messageAudienceRoleTeachersLabel'),
            default => $this->translator->trans('messageAudienceRoleStudentsLabel'),
        };

        return \sprintf('%s — %s', [] !== $programNames ? implode(', ', $programNames) : $label, $roleLabel);
    }
}
