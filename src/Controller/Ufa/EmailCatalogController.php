<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Service\PlatformEmail\PlatformEmailCatalog;
use App\Service\PlatformEmail\PlatformEmailPreviewer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// "UFA > Configuration > Emails" - the mails the platform sends, to read only: the list on the
// left, the chosen one (subject + body) in the middle, what varies from one send to the next
// written as $PERIODE, $ALTERNANT... Nothing here edits a text or sends anything.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class EmailCatalogController extends AbstractController
{
    #[Route(path: '/ufa/configuration/emails/{key}', name: 'app_ufa_configuration_emails', defaults: ['key' => null], methods: ['GET'])]
    public function index(?string $key, PlatformEmailCatalog $catalog, PlatformEmailPreviewer $previewer): Response
    {
        $emails = $catalog->all();
        $selected = null === $key ? $emails[0] : ($catalog->find($key) ?? throw $this->createNotFoundException());

        $groups = [];
        foreach ($emails as $email) {
            $groups[$email->group->value]['group'] = $email->group;
            $groups[$email->group->value]['emails'][] = $email;
        }

        return $this->render('ufa/emails/index.html.twig', [
            'groups' => array_values($groups),
            'selected' => $selected,
            'preview' => $previewer->preview($selected),
        ]);
    }
}
