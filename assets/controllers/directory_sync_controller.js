import { Controller } from '@hotwired/stimulus';

/**
 * Confirms, then POSTs to trigger the LDAP -> local User table sync (see
 * templates/directory/sync.html.twig), showing a spinner while the request is in
 * flight and a summary (or error) message once the response comes back.
 */
export default class extends Controller {
    static targets = ['button', 'spinner', 'summary', 'error'];

    static values = {
        url: String,
        token: String,
        confirmMessage: String,
        summaryMessage: String,
    };

    async run() {
        if (!window.confirm(this.confirmMessageValue)) {
            return;
        }

        this.buttonTarget.classList.add('d-none');
        this.summaryTarget.classList.add('d-none');
        this.errorTarget.classList.add('d-none');
        this.spinnerTarget.classList.remove('d-none');

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue },
            });

            if (!response.ok) {
                throw new Error(`Unexpected response status: ${response.status}`);
            }

            const data = await response.json();

            this.summaryTarget.textContent = this.summaryMessageValue.replace('%count%', data.createdCount);
            this.summaryTarget.classList.remove('d-none');
        } catch {
            this.errorTarget.classList.remove('d-none');
        } finally {
            this.spinnerTarget.classList.add('d-none');
            this.buttonTarget.classList.remove('d-none');
        }
    }
}
