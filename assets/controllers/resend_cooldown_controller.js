import { Controller } from '@hotwired/stimulus';

/**
 * Generic client-side pacing countdown for a "resend" button (e.g. magic_login_sent.html.twig's
 * "Renvoyer le lien") - not a security control, the real abuse guard is the server-side rate
 * limiter (see config/packages/rate_limiter.yaml's magic_login_request policy). This only paces
 * repeat clicks in the UI; the countdown resets on every fresh page load, nothing persisted.
 * Bound directly on the button element.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        seconds: { type: Number, default: 60 },
        readyLabel: String,
        countdownLabelTemplate: String,
    };

    connect() {
        this.remaining = this.secondsValue;
        this.element.disabled = true;
        this.tick();
    }

    disconnect() {
        window.clearTimeout(this.timeout);
    }

    tick() {
        if (this.remaining <= 0) {
            this.element.disabled = false;
            this.element.textContent = this.readyLabelValue;
            return;
        }

        this.element.textContent = this.countdownLabelTemplateValue.replace('%seconds%', String(this.remaining));
        this.remaining -= 1;
        this.timeout = window.setTimeout(() => this.tick(), 1000);
    }
}
