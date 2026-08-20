<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The handful of things every Outils > Sondages screen needs - the two-tab shell and the current
 * user, narrowed to App\Entity\User.
 *
 * Same shape as SettingsTabTrait and ProgramSettingsTabTrait: a tab shell gets a controller per
 * tab, and the genuinely shared helpers live here rather than in a base class.
 */
trait SurveyTabTrait
{
    /**
     * The two tabs, each with the count the mockup shows beside its label. A series has no tab of
     * its own: one enters it from one of its waves, never from the menu.
     *
     * @return list<array{label: string, url: string, active: bool, count: int|null}>
     */
    private function surveyTabs(string $currentRoute, ?int $templateCount = null, ?int $campaignCount = null): array
    {
        return [
            [
                'label' => 'surveyTemplatesTabLabel',
                'url' => $this->generateUrl('app_surveys_templates'),
                'active' => 'app_surveys_templates' === $currentRoute,
                'count' => $templateCount,
            ],
            [
                'label' => 'surveyCampaignsTabLabel',
                'url' => $this->generateUrl('app_surveys_campaigns'),
                'active' => 'app_surveys_campaigns' === $currentRoute,
                'count' => $campaignCount,
            ],
        ];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }
}
