<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\LdapManagePassword;
use App\Enum\Feature;
use App\Repository\LdapManagePasswordRepository;
use App\Service\DataTableParams;
use App\Service\QueueStateFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annuaire > Mots de passe, which is a journal and nothing else: it reports where each
 * ldap_manage_password request stands, and offers no way to create one.
 *
 * It did until 2026-08-24 - a "Réinitialiser un mot de passe" screen picking any user, plus the
 * same action on a user's own fiche. Both were removed rather than kept, because the "Voir" button
 * that made them useful went at the same time: a reset nobody can read hands the account a random
 * password that locks its owner out. What replaces them is the passwordless mailed link
 * (App\Service\MagicLoginService) for anyone who can use it, and samba-tool on the domain
 * controller for the one case it does not cover - an account with no confirmed contact address.
 * ROLE_ADMIN used to be a second such case; the link stopped excluding it on 2026-08-27, precisely
 * because samba-tool is not an answer somebody locked out at 8am has.
 *
 * So the only thing that still fills this queue is the user's own profile
 * (App\Controller\ProfileController::changePassword()) - and this screen is how staff sees whether
 * it went through. Adding an action back here means answering how its result reaches the person
 * concerned, which is the question that removed it.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Directory)]
class DirectoryPasswordController extends AbstractController
{
    #[Route(path: '/directory/passwords', name: 'app_directory_passwords')]
    public function index(): Response
    {
        return $this->render('directory/passwords.html.twig');
    }

    #[Route(path: '/directory/passwords/data', name: 'app_directory_passwords_data')]
    public function data(Request $request, LdapManagePasswordRepository $repository, QueueStateFormatter $stateFormatter): JsonResponse
    {
        $params = DataTableParams::fromRequest($request);
        [$draw, $start, $length, $search] = [$params->draw, $params->start, $params->length, $params->search];

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (LdapManagePassword $ldapManagePassword): array => [
                    'id' => $ldapManagePassword->getId(),
                    'fullName' => $ldapManagePassword->getUser()->getDisplayName() ?? $ldapManagePassword->getUser()->getUsername(),
                    'login' => $ldapManagePassword->getLogin(),
                    'statusLabel' => $stateFormatter->label($ldapManagePassword->getState()),
                    'statusClass' => $stateFormatter->cssClass($ldapManagePassword->getState()),
                    'addedAt' => $ldapManagePassword->getAddedAt()->format('d/m/Y H:i'),
                ],
                $rows,
            ),
        ]);
    }
}
