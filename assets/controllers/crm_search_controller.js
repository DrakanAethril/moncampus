import { Controller } from '@hotwired/stimulus';

/**
 * The CRM home's search: as one types, the server's list of dossiers (app_crm_search) opens under
 * the field. ↑ ↓ choose, Entrée opens, Échap clears.
 */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { url: String };

    connect() {
        this.index = 0;
        if (this.inputTarget.value.trim() !== '') this.query();
    }

    query() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetch(), 120);
    }

    async fetch() {
        const q = this.inputTarget.value.trim();
        if (q === '') {
            this.render('');
            return;
        }
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', q);
        const asked = q;
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok || asked !== this.inputTarget.value.trim()) return;
            this.render(await response.text());
        } catch {
            // The list simply stays as it was.
        }
    }

    render(html) {
        this.listTarget.innerHTML = html;
        const open = html.trim() !== '';
        this.listTarget.hidden = !open;
        this.element.classList.toggle('is-open', open);
        this.inputTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.index = 0;
        this.highlight();
    }

    get hits() {
        return [...this.listTarget.querySelectorAll('.cm-crm-hit')];
    }

    highlight() {
        this.hits.forEach((hit, i) => {
            hit.classList.toggle('is-active', i === this.index);
            hit.setAttribute('aria-selected', i === this.index ? 'true' : 'false');
        });
        const active = this.hits[this.index];
        if (active) {
            this.inputTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }

    hover(event) {
        const hit = event.target.closest('.cm-crm-hit');
        const i = this.hits.indexOf(hit);
        if (i >= 0 && i !== this.index) {
            this.index = i;
            this.highlight();
        }
    }

    key(event) {
        const hits = this.hits;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!hits.length) return;
            event.preventDefault();
            this.index = (this.index + (event.key === 'ArrowDown' ? 1 : -1) + hits.length) % hits.length;
            this.highlight();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            hits[this.index]?.querySelector('a')?.click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.inputTarget.value = '';
            this.render('');
        }
    }
}
