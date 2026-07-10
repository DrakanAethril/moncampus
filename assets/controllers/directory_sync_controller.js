import { Controller } from '@hotwired/stimulus';

/**
 * Confirms, then POSTs to trigger the LDAP -> local User table sync (see
 * templates/directory/sync.html.twig), showing a spinner while the request is in
 * flight and a summary (or error) message once the response comes back.
 *
 * Dispatches a bubbling "directory-sync:completed" CustomEvent on success so a DataTable
 * elsewhere on the page (a separate controller instance, not necessarily a DOM descendant/
 * ancestor of this one - see templates/settings/groups.html.twig, where the button lives in the
 * page header and the table lives in the page body) can reload itself via
 * data-action="directory-sync:completed@window->datatable#reload" without this controller
 * needing to know the table exists at all.
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

            // %updatedCount% is only present in Directory > Users' own summary message (see
            // App\Service\LdapUserSyncer, the only syncer that also refreshes existing rows) -
            // a no-op replace on the Groups/Services/Computers messages, which don't have it.
            this.summaryTarget.textContent = this.summaryMessageValue
                .replace('%count%', data.createdCount)
                .replace('%updatedCount%', data.updatedCount ?? 0);
            this.summaryTarget.classList.remove('d-none');
            this.dispatch('completed', { detail: { createdCount: data.createdCount } });
        } catch {
            this.errorTarget.classList.remove('d-none');
        } finally {
            this.spinnerTarget.classList.add('d-none');
            this.buttonTarget.classList.remove('d-none');
        }
    }
}
