import { Controller } from '@hotwired/stimulus';

/**
 * The host declaration form: shows only the fields the chosen modes need, tests the connection
 * before anything is saved, and reads the certificate the host presents.
 *
 * Testing before saving is the point of the button, not a convenience: testing a *saved*
 * declaration would mean storing a broken one first, every time somebody mistypes a password. The
 * endpoint therefore receives the values currently in the form, builds a throwaway client from
 * them and answers - it stores nothing, and it never sends a secret back.
 *
 * The certificate block shows two digests under two different names. They are hashes of two
 * different things: the fingerprint Proxmox displays hashes the certificate, while the pin the TLS
 * option wants hashes the public key. Pasting one where the other belongs fails with a TLS error
 * naming neither, so "Utiliser cette épingle" fills the field rather than inviting anyone to type
 * it.
 *
 * Every sentence this controller shows arrives as a Stimulus value: the display language is
 * French and lives in the translation catalogue, never in a .js file.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'tokenNameField',
        'caField',
        'pinField',
        'pinInput',
        'testButton',
        'testResult',
        'certificate',
        'certificateSubject',
        'certificateFingerprint',
        'certificatePin',
    ];

    static values = {
        token: String,
        testUrl: String,
        certificateUrl: String,
        hostId: Number,
        runningMessage: String,
        failedMessage: String,
        poolMissingMessage: String,
        selfSignedMessage: String,
        expiresMessage: String,
    };

    connect() {
        this.credentialKindChanged();
        this.tlsModeChanged();
    }

    credentialKindChanged() {
        // A token id is meaningless for a password credential, and leaving it on screen is how a
        // half-filled second mode ends up saved next to the first.
        this.#toggle(this.tokenNameFieldTarget, this.#checkedValue('credentialKind') === 'api_token');
    }

    tlsModeChanged() {
        const mode = this.#checkedValue('tlsMode');

        this.#toggle(this.caFieldTarget, mode === 'ca');
        this.#toggle(this.pinFieldTarget, mode === 'pin');
    }

    async testConnection() {
        this.testButtonTarget.disabled = true;
        this.#say(this.runningMessageValue, 'var(--cm-muted)');

        try {
            const answer = await this.#post(this.testUrlValue, {
                hostId: this.hostIdValue,
                hostname: this.#field('hostname'),
                port: Number(this.#field('port')) || 8006,
                credentialKind: this.#checkedValue('credentialKind'),
                username: this.#field('username'),
                realm: this.#field('realm'),
                tokenName: this.#field('tokenName'),
                secret: this.#field('secret'),
                tlsMode: this.#checkedValue('tlsMode'),
                tlsCaPem: this.#field('tlsCaPem'),
                tlsPinSha256: this.#field('tlsPinSha256'),
                managedPool: this.#field('managedPool'),
            });

            if (!answer.ok) {
                this.#say(answer.message, 'var(--cm-action-danger)');
                return;
            }

            // Reachable but misdeclared is worth saying out loud here: a pool that does not exist
            // empties the scope guard silently, and the failure would only surface much later as
            // an action that quietly does nothing.
            const suffix = answer.poolMissing ? ` — ${this.poolMissingMessageValue.replace('%pool%', answer.pool)}` : '';

            this.#say(`Proxmox VE ${answer.version}${suffix}`, answer.poolMissing ? 'var(--cm-warn-tx)' : 'var(--cm-positive-tx)');
        } catch {
            this.#say(this.failedMessageValue, 'var(--cm-action-danger)');
        } finally {
            this.testButtonTarget.disabled = false;
        }
    }

    async inspectCertificate() {
        this.#say(this.runningMessageValue, 'var(--cm-muted)');

        try {
            const answer = await this.#post(this.certificateUrlValue, {
                hostname: this.#field('hostname'),
                port: Number(this.#field('port')) || 8006,
            });

            if (!answer.ok) {
                this.#say(answer.message, 'var(--cm-action-danger)');
                return;
            }

            const notes = [answer.subject];

            if (answer.selfSigned) {
                notes.push(this.selfSignedMessageValue);
            }

            if (answer.validUntil) {
                notes.push(this.expiresMessageValue.replace('%date%', answer.validUntil));
            }

            this.certificateSubjectTarget.textContent = notes.join(' · ');
            this.certificateFingerprintTarget.textContent = answer.fingerprint;
            this.certificatePinTarget.textContent = answer.publicKeyPin;
            this.certificateTarget.hidden = false;
            this.#say('', 'var(--cm-muted)');
        } catch {
            this.#say(this.failedMessageValue, 'var(--cm-action-danger)');
        }
    }

    usePin() {
        this.pinInputTarget.value = this.certificatePinTarget.textContent.trim();

        const pinRadio = this.element.querySelector('input[type="radio"][value="pin"]');

        if (pinRadio) {
            pinRadio.checked = true;
            this.tlsModeChanged();
        }
    }

    async #post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue, 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            throw new Error(`Unexpected response status: ${response.status}`);
        }

        return response.json();
    }

    /** Bootstrap's display utilities carry !important, so the plain `hidden` attribute alone would lose. */
    #toggle(element, visible) {
        element.hidden = !visible;
        element.style.display = visible ? '' : 'none';
    }

    #field(name) {
        const input = this.element.querySelector(`[name$="[${name}]"]`);

        return input ? input.value : '';
    }

    #checkedValue(name) {
        const input = this.element.querySelector(`input[type="radio"][name$="[${name}]"]:checked`);

        return input ? input.value : '';
    }

    #say(message, color) {
        this.testResultTarget.textContent = message;
        this.testResultTarget.style.color = color;
    }
}
