import { Controller } from '@hotwired/stimulus';

/**
 * Login card tab switcher (Mot de passe / Lien par e-mail) - design/design_handoff_connexion.
 * Both tabs stay on the same page/card; only visibility and ARIA state change, never a
 * navigation. The typed identifier is carried across tabs by copying `.value` between the two
 * distinct fields (not a single shared input) since they belong to two different forms/backends -
 * LdapAuthenticator's `_username` vs MagicLoginRequestType's `email`.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'passwordTab', 'magicLinkTab', 'passwordPanel', 'magicLinkPanel',
        'usernameInput', 'magicLinkEmailInput', 'passwordSubmitButton', 'passwordSubmitSpinner',
    ];

    connect() {
        // A page the browser restores from its back/forward cache comes back exactly as it was
        // left - including the submit button this controller disabled on the way out - and Stimulus
        // does not re-connect on such a restore, so nothing would ever re-enable it: pressing
        // "Se connecter" after going back then does literally nothing. `pageshow` is the only event
        // that fires in that case. (The server-side half of going back while still logged in is
        // App\Controller\SecurityController::login(), which redirects to the dashboard - but a
        // restored page never asks the server anything, hence this.)
        this.resetOnPageShow = () => this.resetPasswordSubmitButton();
        window.addEventListener('pageshow', this.resetOnPageShow);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.resetOnPageShow);
    }

    switchToPassword(event) {
        if (event) event.preventDefault();
        this.activate(false);
    }

    switchToMagicLink(event) {
        if (event) event.preventDefault();
        this.activate(true);
    }

    // Standard WAI-ARIA tablist keyboard support (Left/Right/Home/End move focus + selection).
    onTablistKeydown(event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const toMagicLink = ['ArrowRight', 'End'].includes(event.key);
        this.activate(toMagicLink);
        (toMagicLink ? this.magicLinkTabTarget : this.passwordTabTarget).focus();
    }

    // "Mot de passe oublié ?" has no dedicated self-service reset flow in this app - the magic
    // link tab already offers a real passwordless way back in, so it switches there instead of
    // navigating to the generic "lost account access" ticket form.
    forgotPassword(event) {
        event.preventDefault();
        this.switchToMagicLink();
    }

    activate(toMagicLink) {
        if (toMagicLink) {
            this.magicLinkEmailInputTarget.value = this.usernameInputTarget.value;
        } else {
            this.usernameInputTarget.value = this.magicLinkEmailInputTarget.value;
        }

        this.passwordTabTarget.classList.toggle('is-active', !toMagicLink);
        this.passwordTabTarget.setAttribute('aria-selected', String(!toMagicLink));
        this.passwordTabTarget.setAttribute('tabindex', toMagicLink ? '-1' : '0');
        this.magicLinkTabTarget.classList.toggle('is-active', toMagicLink);
        this.magicLinkTabTarget.setAttribute('aria-selected', String(toMagicLink));
        this.magicLinkTabTarget.setAttribute('tabindex', toMagicLink ? '0' : '-1');

        this.passwordPanelTarget.hidden = toMagicLink;
        this.magicLinkPanelTarget.hidden = !toMagicLink;

        (toMagicLink ? this.magicLinkEmailInputTarget : this.usernameInputTarget).focus();
    }

    // Cosmetic loading state only - this is a real form POST/navigation, not an AJAX call, so the
    // spinner just covers the brief round trip before the browser navigates away.
    submitPasswordForm() {
        this.passwordSubmitButtonTarget.disabled = true;
        this.passwordSubmitSpinnerTarget.classList.remove('d-none');
    }

    resetPasswordSubmitButton() {
        this.passwordSubmitButtonTarget.disabled = false;
        this.passwordSubmitSpinnerTarget.classList.add('d-none');
    }
}
