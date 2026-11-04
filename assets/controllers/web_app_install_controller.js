import { Controller } from '@hotwired/stimulus';
import { canPromptInstall, onInstallPromptChange, promptInstall, wasInstalledNow } from '../web_app/install_prompt.js';

/**
 * « Installer beaup-app » (templates/install/index.html.twig, App\Controller\InstallController).
 *
 * Every state is decided here, in the browser, because only the browser knows it:
 * - running standalone (launched from its icon) - « déjà installée », and nothing else to do;
 * - an iPhone or an iPad - Safari has no install event, the Share-menu steps are the way, so their
 *   card is marked as the one that applies;
 * - anything else - the install button, live only while the browser's offer is held
 *   (assets/web_app/install_prompt.js), and a line saying why when it is not.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['installed', 'choices', 'promptCard', 'iosCard', 'button', 'status'];
    static values = {
        unavailableText: String,
        iosText: String,
        acceptedText: String,
        dismissedText: String,
    };

    connect() {
        this.outcome = null;
        if (this.runsStandalone()) {
            this.installedTarget.hidden = false;
            this.choicesTarget.hidden = true;

            return;
        }
        this.ios = this.isAppleTouchDevice();
        (this.ios ? this.iosCardTarget : this.promptCardTarget).classList.add('is-current');
        // Safari never makes the offer: a button that cannot light would only be a dead end there.
        this.buttonTarget.parentElement.hidden = this.ios;
        this.unsubscribe = onInstallPromptChange(() => this.render());
        this.render();
    }

    disconnect() {
        this.unsubscribe?.();
    }

    async install() {
        if (!canPromptInstall()) {
            return;
        }
        this.buttonTarget.disabled = true;
        this.outcome = await promptInstall();
        this.render();
    }

    render() {
        const available = canPromptInstall();
        this.buttonTarget.disabled = !available;

        let text = '';
        if (wasInstalledNow() || 'accepted' === this.outcome) {
            text = this.acceptedTextValue;
        } else if (available) {
            text = '';
        } else if ('dismissed' === this.outcome) {
            text = this.dismissedTextValue;
        } else if (this.ios) {
            text = this.iosTextValue;
        } else {
            text = this.unavailableTextValue;
        }
        this.statusTarget.textContent = text;
        this.statusTarget.hidden = '' === text;
    }

    runsStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || true === window.navigator.standalone;
    }

    isAppleTouchDevice() {
        const ua = window.navigator.userAgent;

        // An iPad on iPadOS 13+ presents itself as a Mac; its touch screen is what gives it away.
        return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && window.navigator.maxTouchPoints > 1);
    }
}
