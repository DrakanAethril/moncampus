import { Controller } from '@hotwired/stimulus';

/*
 * The Jobboard's screen: the tag-style filters, the detail panel over the list, and « Afficher 40
 * offres de plus ».
 *
 * Three deliberate choices here.
 *
 * The filters submit the form and let the server answer. The list is filtered, ordered and paginated
 * server-side anyway (the data set grows at every pass of the veille), so filtering in the browser
 * would be a second, disagreeing implementation - and the URL stays bookmarkable.
 *
 * The detail panel and the extra rows arrive as **rendered HTML**, not as JSON to build markup from.
 * One template renders a row, one renders a panel, and the rule that a non-administrator never sees
 * the source cannot be forgotten by a second rendering path.
 *
 * Only one menu is open at a time, and opening an offer closes it - that is the handoff's own rule
 * and the reason every menu is closed from one place here.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['form', 'select', 'menu', 'value', 'rows', 'more', 'empty', 'veil', 'panel', 'row'];

    static values = { offersUrl: String, detailUrl: String, cursor: String };

    connect() {
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.#closeMenus();
                this.closeDetail();
            }
        };
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.#closeMenus();
            }
        };

        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('click', this.onOutside);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('click', this.onOutside);
    }

    submit() {
        this.formTarget.requestSubmit();
    }

    toggleMenu(event) {
        const wrapper = event.currentTarget.closest('[data-jobboard-target="select"]');

        if (!wrapper) {
            return;
        }

        const open = wrapper.classList.contains('is-open');
        this.#closeMenus();

        if (!open) {
            wrapper.classList.add('is-open');
            wrapper.querySelector('.cm-jb-select')?.setAttribute('aria-expanded', 'true');
        }
    }

    keyMenu(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.toggleMenu(event);
        }
    }

    pickValue(event) {
        const wrapper = event.currentTarget.closest('[data-jobboard-target="select"]');

        if (!wrapper) {
            return;
        }

        const checked = [...wrapper.querySelectorAll('.cm-jb-menu input[type="checkbox"]')]
            .filter((box) => box.checked)
            .map((box) => box.value);

        wrapper.querySelector('[data-jobboard-target="value"]').value = checked.join(',');
        this.submit();
    }

    removeValue(event) {
        // Without this the click would reach the field underneath and reopen the menu the reader
        // just used - the handoff calls it out by name.
        event.stopPropagation();

        const wrapper = event.currentTarget.closest('[data-jobboard-target="select"]');
        const input = wrapper?.querySelector('[data-jobboard-target="value"]');

        if (!input) {
            return;
        }

        input.value = input.value
            .split(',')
            .filter((value) => value !== '' && value !== event.currentTarget.dataset.value)
            .join(',');

        this.submit();
    }

    async loadMore(event) {
        const button = event.currentTarget;
        const url = new URL(this.offersUrlValue, window.location.origin);

        new URLSearchParams(new FormData(this.formTarget)).forEach((value, key) => {
            if (value !== '') {
                url.searchParams.set(key, value);
            }
        });
        url.searchParams.set('cursor', this.cursorValue);

        button.disabled = true;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            this.rowsTarget.insertAdjacentHTML('beforeend', payload.html);
            this.cursorValue = payload.cursor ?? '';

            if (this.cursorValue === '') {
                button.closest('.cm-jb-more')?.remove();
            }
        } finally {
            button.disabled = false;
        }
    }

    async openDetail(event) {
        const row = event.currentTarget;
        const id = row.dataset.id;

        if (!id) {
            return;
        }

        this.#closeMenus();
        this.rowTargets.forEach((other) => other.classList.toggle('is-open', other === row));

        const response = await fetch(this.detailUrlValue.replace(/\/0$/, `/${id}`), { headers: { Accept: 'text/html' } });

        if (!response.ok) {
            return;
        }

        this.panelTarget.innerHTML = await response.text();
        this.veilTarget.hidden = false;
        this.panelTarget.hidden = false;
        this.panelTarget.querySelector('.cm-jb-panel__close')?.focus();
    }

    keyDetail(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.openDetail(event);
        }
    }

    closeDetail() {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = true;
            this.panelTarget.innerHTML = '';
        }

        if (this.hasVeilTarget) {
            this.veilTarget.hidden = true;
        }

        this.rowTargets.forEach((row) => row.classList.remove('is-open'));
    }

    #closeMenus() {
        this.selectTargets.forEach((wrapper) => {
            wrapper.classList.remove('is-open');
            wrapper.querySelector('.cm-jb-select')?.setAttribute('aria-expanded', 'false');
        });
    }
}
