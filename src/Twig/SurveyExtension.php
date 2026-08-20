<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SurveyCampaign;
use App\Enum\MessageAudienceType;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The one-line reading of who a campaign aims at - « SIO1 · SIO2 · étudiants », « Tous les
 * enseignants ».
 *
 * The audience is a *set* of types plus its programs and the two include flags, so composing that
 * sentence in Twig would mean the same nested condition on every screen that prints it. It is
 * written once here, and it deliberately says the audience as it was *defined* - the number of
 * people it actually reached is the frozen target's business, and it is printed beside it.
 */
class SurveyExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('survey_audience_summary', $this->audienceSummary(...)),
        ];
    }

    public function audienceSummary(SurveyCampaign $campaign): string
    {
        $parts = [];

        foreach ($campaign->getAudienceTypes() as $type) {
            if (MessageAudienceType::Program === $type) {
                foreach ($campaign->getPrograms() as $program) {
                    $parts[] = $program->getDisplayShortName();
                }

                // Which half of the class, said once for all of them - the flags are per audience,
                // not per program.
                if ($campaign->isIncludeStudents()) {
                    $parts[] = $this->translator->trans('surveyAudienceStudentsLabel');
                }
                if ($campaign->isIncludeTeachers()) {
                    $parts[] = $this->translator->trans('surveyAudienceTeachersLabel');
                }

                continue;
            }

            $parts[] = $this->translator->trans($type->labelKey());
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));

        return [] === $parts ? $this->translator->trans('surveyAudienceNobodyLabel') : implode(' · ', $parts);
    }
}
