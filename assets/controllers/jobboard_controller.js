import { Controller } from '@hotwired/stimulus';

/*
 * The Jobboard's screen: the tag-style filters and « Afficher 40 offres de plus ».
 *
 * Three deliberate choices here.
 *
 * The filters submit the form and let the server answer. The list is filtered, ordered and paginated
 * server-side anyway (the data set grows at every pass of the veille), so filtering in the browser
 * would be a second, disagreeing implementation - and the URL stays bookmarkable.
 *
 * The extra rows arrive as **rendered HTML**, not as JSON to build markup from: one template renders
 * a row, so a row cannot say two different things depending on which door it came from.
 *
 * A row is a plain link to the advert and owes nothing to this controller - there is no detail panel
 * to open, so opening an offer is the browser's own business, Ctrl-click and all.
 *
 * Only one menu is open at a time, which is the handoff's own rule and the reason every menu is
 * closed from one place here.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['form', 'select', 'menu', 'value', 'rows', 'more', 'empty', 'search', 'noMatch'];

    static values = { offersUrl: String, cursor: String };

    connect() {
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.#closeMenus();
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
            // A menu that has a search field opens on it: the reader who asked for a searchable
            // list is already typing.
            wrapper.querySelector('.cm-jb-search__input')?.focus();
        }
    }

    keyMenu(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.toggleMenu(event);
        }
    }

    /**
     * Narrows the options of one menu to what has been typed. Purely visual: a hidden option keeps
     * its checkbox and its value, so searching can never untick what was already chosen.
     */
    filterOptions(event) {
        const wrapper = event.currentTarget.closest('[data-jobboard-target="select"]');

        if (!wrapper) {
            return;
        }

        const needle = this.#fold(event.currentTarget.value);
        let shown = 0;

        wrapper.querySelectorAll('.cm-jb-option').forEach((option) => {
            const match = needle === '' || this.#fold(option.dataset.label ?? '').includes(needle);
            option.hidden = !match;
            shown += match ? 1 : 0;
        });

        const empty = wrapper.querySelector('[data-jobboard-target="noMatch"]');

        if (empty) {
            empty.hidden = shown > 0;
        }
    }

    keySearch(event) {
        // The field lives inside a GET form: Enter would submit it and close the menu the reader is
        // still using. Escape closes the menu rather than clearing the field, as everywhere else.
        if (event.key === 'Enter') {
            event.preventDefault();
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

    #closeMenus() {
        this.selectTargets.forEach((wrapper) => {
            wrapper.classList.remove('is-open');
            wrapper.querySelector('.cm-jb-select')?.setAttribute('aria-expanded', 'false');
        });
    }

    /** Lower-case and accent-free, so « Télétravail » is found by typing « teletravail ». */
    #fold(value) {
        return value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
}
