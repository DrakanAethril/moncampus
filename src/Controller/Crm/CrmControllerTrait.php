<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmOrigin;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the CRM's controllers share: the user, the CSRF check, the work year and the dialogs.
 *
 * A dialog is a fragment the page fetches into its <dialog> (crm_dialog_controller.js). Its form
 * posts back through fetch: a refusal answers the same fragment with its reasons and a 422, a
 * success a redirect the page follows.
 */
trait CrmControllerTrait
{
    private const string CSRF_ID = 'crm';
    private const string WORK_YEAR = 'crm_work_year';

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function workYear(Request $request, CrmSettings $settings): ?SchoolYear
    {
        $wanted = $request->getSession()->get(self::WORK_YEAR);

        return $settings->workYear(\is_int($wanted) ? $wanted : null);
    }

    private function openedYear(SchoolYear $year, CrmSettings $settings): SchoolYear
    {
        if (!$settings->isOpened($year)) {
            throw $this->createNotFoundException();
        }

        return $year;
    }

    /** A formation of a year opened in the CRM; any other answers 404. */
    private function crmProgram(Program $program, CrmSettings $settings): Program
    {
        $year = $program->getSchoolYear();
        if (null === $year || !$settings->isOpened($year) || $program->isTestProgram()) {
            throw $this->createNotFoundException();
        }

        return $program;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dialog(string $template, array $parameters, ?CrmRefusal $refusal = null, ?TranslatorInterface $translator = null): Response
    {
        $parameters['errors'] = null === $refusal || null === $translator ? [] : $refusal->texts($translator);

        return $this->render($template, $parameters, new Response(status: null === $refusal ? 200 : 422));
    }

    /**
     * The origins ticked in a form, in the list's order.
     *
     * @param list<CrmOrigin> $all
     *
     * @return list<CrmOrigin>
     */
    private static function originsFrom(Request $request, array $all, string $field = 'origins'): array
    {
        $ids = array_map('intval', $request->request->all()[$field] ?? []);

        return array_values(array_filter($all, static fn (CrmOrigin $o): bool => \in_array((int) $o->getId(), $ids, true)));
    }

    private function dossierUrl(CrmDossier $dossier): string
    {
        return $this->generateUrl('app_crm_dossier', ['id' => $dossier->getId()]);
    }
}
