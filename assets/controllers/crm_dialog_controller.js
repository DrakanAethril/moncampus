import { Controller } from '@hotwired/stimulus';

/**
 * The CRM's dialogs (CRM des inscriptions). Each button names the address of its dialog, drawn by
 * the server; this controller fetches it into the page's <dialog> and posts its form back through
 * fetch: a refusal comes back as the same fragment with its reasons (422) and replaces it, a
 * success as a redirect the page follows with Turbo - flash message included.
 */
export default class extends Controller {
    static targets = ['dialog', 'content'];

    connect() {
        this.submit = this.submit.bind(this);
        this.contentTarget.addEventListener('submit', this.submit);
        // A screen may ask for one of its dialogs at once (a year just opened: choose its formations).
        this.element.querySelector('[data-crm-autoopen]')?.click();
    }

    disconnect() {
        this.contentTarget.removeEventListener('submit', this.submit);
    }

    async open(event) {
        event.preventDefault();
        const url = event.params.url;
        if (!url) return;
        this.trigger = event.currentTarget;

        let response;
        try {
            response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
        } catch {
            return;
        }
        if (!response.ok) return;

        this.show(await response.text());
    }

    show(html) {
        this.contentTarget.innerHTML = html;
        const wide = this.contentTarget.querySelector('[data-crm-wide]');
        this.dialogTarget.classList.toggle('is-wide', !!wide);
        if (!this.dialogTarget.open) this.dialogTarget.showModal();
        const first = this.contentTarget.querySelector('[autofocus], input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea, select');
        first?.focus();
    }

    async submit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.crmPlain !== undefined) return;
        event.preventDefault();

        const submitter = event.submitter;
        const body = new FormData(form, submitter ?? undefined);
        form.querySelectorAll('button[type=submit]').forEach((button) => { button.disabled = true; });

        let response;
        try {
            response = await fetch(submitter?.hasAttribute('formaction') ? submitter.formAction : form.action, { method: 'POST', body, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
        } catch {
            form.querySelectorAll('button[type=submit]').forEach((button) => { button.disabled = false; });
            return;
        }

        if (response.redirected) {
            this.dialogTarget.close();
            this.go(response.url);
            return;
        }

        this.show(await response.text());
    }

    go(url) {
        if (window.Turbo) {
            window.Turbo.visit(url, { action: 'replace' });
        } else {
            window.location.assign(url);
        }
    }

    close() {
        if (this.dialogTarget.open) this.dialogTarget.close();
    }

    // A click on the dimmed backdrop lands on the <dialog> itself, never on its content.
    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    closed() {
        this.contentTarget.innerHTML = '';
        this.trigger?.focus();
    }
}
