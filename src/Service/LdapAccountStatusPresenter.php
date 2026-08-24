<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageAccount;
use App\Enum\LdapAccountAction;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns one ldap_manage_account row into the banner the fiche shows, and into the row the journal
 * shows - the single place that decides what an administrator reads about a request.
 *
 * It exists because the same answer is produced twice: once by the server rendering the fiche, once
 * by the polling endpoint two seconds later. The banner is *not* the polling's creature - refresh
 * the page, come back from another machine, and it is there in the right state - so both have to
 * say exactly the same thing about the same row.
 *
 * The one rule worth stating out loud is the third level. "Succeeded" is two states, not one:
 * verified, which is green, and unverified, which is orange and carries the reason. Nothing here
 * ever shows a state = 2 row in green on the strength of state alone.
 *
 * @phpstan-type AccountStatus array{
 *     id: int|null,
 *     action: string,
 *     actionLabel: string,
 *     level: 'pending'|'running'|'ok'|'warn'|'crit',
 *     badgeLabel: string,
 *     title: string,
 *     detail: string,
 *     log: string|null,
 *     login: string,
 *     newLogin: string|null,
 *     requestedBy: string,
 *     requestedAt: string,
 *     endedAt: string|null,
 *     retryable: bool,
 *     done: bool
 * }
 */
class LdapAccountStatusPresenter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly QueueStateFormatter $stateFormatter,
    ) {
    }

    /**
     * @return AccountStatus|null null when the account has never gone through this queue, which is
     *                            the ordinary case - the fiche then shows no banner at all
     */
    public function present(?LdapManageAccount $request): ?array
    {
        if (null === $request) {
            return null;
        }

        $level = $this->level($request);
        $parameters = [
            '%old%' => $request->getLogin(),
            '%new%' => $request->getNewLogin() ?? '',
            '%by%' => $request->getAddedBy(),
            '%at%' => $request->getEndedAt()?->format('H:i:s') ?? '',
        ];

        return [
            'id' => $request->getId(),
            'action' => $request->getActionType()->value,
            'actionLabel' => $this->translator->trans($request->getActionType()->labelKey()),
            'level' => $level,
            'badgeLabel' => $this->badgeLabel($request, $level),
            'title' => $this->translator->trans($this->titleKey($request->getActionType(), $level), $parameters),
            'detail' => $this->translator->trans($this->detailKey($request, $level), $parameters),
            // The script's own output, and only on a failure: these consumers write to `log` on the
            // way through as well, so a succeeded row's log is progress, not an error to put in
            // front of anybody. Same rule as the "Statut de l'ajout" line just above it.
            'log' => $request->isFailed() && null !== $request->getLog() && '' !== trim($request->getLog())
                ? trim($request->getLog())
                : null,
            'login' => $request->getLogin(),
            'newLogin' => $request->getNewLogin(),
            'requestedBy' => $request->getAddedBy(),
            'requestedAt' => $request->getAddedAt()->format('d/m/Y H:i'),
            'endedAt' => $request->getEndedAt()?->format('d/m/Y H:i'),
            'retryable' => $request->isFailed(),
            // What stops the polling: the script has had its say. An unverified success is done
            // too - the directory may still be re-read, but not by watching this row spin.
            'done' => $request->getState() >= 2,
        ];
    }

    /** @return 'pending'|'running'|'ok'|'warn'|'crit' */
    private function level(LdapManageAccount $request): string
    {
        return match (true) {
            $request->isFailed() => 'crit',
            $request->isSucceededAndVerified() => 'ok',
            $request->isSucceededUnverified() => 'warn',
            1 === $request->getState() => 'running',
            default => 'pending',
        };
    }

    private function badgeLabel(LdapManageAccount $request, string $level): string
    {
        return match ($level) {
            'ok' => $this->translator->trans('ldapAccountStateVerifiedLabel'),
            'warn' => $this->translator->trans('ldapAccountStateUnverifiedLabel'),
            default => $this->stateFormatter->label($request->getState()),
        };
    }

    private function titleKey(LdapAccountAction $action, string $level): string
    {
        if ('warn' === $level) {
            // One sentence for the three actions: what it says is not about the gesture, it is
            // about the script and the directory disagreeing.
            return 'ldapAccountBannerUnverifiedTitle';
        }

        $suffix = match ($level) {
            'ok' => 'DoneTitle',
            'crit' => 'FailedTitle',
            default => 'RunningTitle',
        };

        return 'ldapAccountBanner'.$this->actionKeyPart($action).$suffix;
    }

    private function detailKey(LdapManageAccount $request, string $level): string
    {
        return match ($level) {
            'pending' => 'ldapAccountBannerQueuedDetail',
            'running' => 'ldapAccountBannerStartedDetail',
            'ok' => 'ldapAccountBannerVerifiedDetail',
            // The reason the re-read could not conclude, written by App\Service\LdapAccountVerifier.
            // The fallback covers a row an older version left unverified without saying why.
            'warn' => $request->getVerificationNote() ?? 'ldapAccountVerificationUnknownNote',
            default => 'ldapAccountBanner'.$this->actionKeyPart($request->getActionType()).'FailedDetail',
        };
    }

    private function actionKeyPart(LdapAccountAction $action): string
    {
        return match ($action) {
            LdapAccountAction::Disable => 'Disable',
            LdapAccountAction::Enable => 'Enable',
            LdapAccountAction::LoginChange => 'LoginChange',
        };
    }
}
