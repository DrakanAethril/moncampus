import { Controller } from '@hotwired/stimulus';

/**
 * The « Outils » panel of the top bar: a grid of columns, and a search field that replaces them.
 *
 * The searchable list is read from the columns themselves rather than passed in - the panel is
 * already rendered from the features and roles of the person looking at it, so reading the DOM is
 * what guarantees the search can never offer a tool the menu did not deliver.
 *
 * Nothing here decides who sees what: the entries, their order and their rights stay entirely in
 * the template.
 */
/*
 * Deliberately not `stimulusFetch: 'lazy'`, unlike most controllers here: a lazy one is loaded when
 * its element becomes visible, and this element is a closed dropdown - it becomes visible on the
 * very open whose `shown.bs.dropdown` the controller has to answer, so the first open would get
 * neither the focused field nor the panel pulled back inside the screen. The top bar is on every
 * authenticated page anyway.
 */
export default class extends Controller {
    static targets = ['search', 'input', 'grid', 'results'];

    // Below this, a panel is read rather than searched (design_handoff_menu_outils_1d): the field is
    // hidden unless the panel holds several groups or enough entries to be worth filtering.
    static SEARCH_THRESHOLD = 8;

    connect() {
        // The Bootstrap events are fired on the toggle, a sibling: listened to on their common
        // parent, which is also the reference to keep - `parentElement` is already null by the time
        // a Turbo morph disconnects the controller.
        this.item = this.element.parentElement;
        this.tools = this.#collect();

        const worthSearching = this.gridTarget.querySelectorAll('.cm-toolsmenu__col').length > 1
            || this.tools.length >= this.constructor.SEARCH_THRESHOLD;

        this.searchTarget.hidden = !worthSearching;

        // The field takes the focus when the panel opens, and an entry typed before a previous close
        // must not still be filtering the columns the next time it does.
        this.onShown = () => {
            this.#fit();

            if (!this.searchTarget.hidden) {
                this.inputTarget.focus();
            }
        };
        this.onHidden = () => {
            this.#reset();
            this.element.style.width = '';
        };

        this.item.addEventListener('shown.bs.dropdown', this.onShown);
        this.item.addEventListener('hidden.bs.dropdown', this.onHidden);
    }

    disconnect() {
        this.item.removeEventListener('shown.bs.dropdown', this.onShown);
        this.item.removeEventListener('hidden.bs.dropdown', this.onHidden);
    }

    filter() {
        const needle = this.#normalize(this.inputTarget.value.trim());

        if (needle === '') {
            this.#reset();

            return;
        }

        const matches = this.tools.filter((tool) => tool.haystack.includes(needle));

        this.resultsTarget.replaceChildren(...matches.map((tool) => this.#result(tool)));
        this.element.classList.add('is-searching');
        this.element.classList.toggle('is-empty', matches.length === 0);
    }

    /**
     * Up and down walk the results, Enter opens the one under the cursor (the first one by default).
     *
     * Escape is left to Bootstrap, which closes the whole panel rather than emptying the field -
     * that is what Escape does everywhere else on this platform (see jobboard_controller), and the
     * field is cleared on close anyway. Intercepting it here would not work in any case: Bootstrap
     * registers its delegated handlers on `document` in the capture phase, so it sees the key
     * before the field does.
     */
    navigate(event) {
        if (!this.element.classList.contains('is-searching')) {
            return;
        }

        const results = [...this.resultsTarget.children];

        if (results.length === 0) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            (results.find((result) => result.classList.contains('is-current')) ?? results[0]).click();

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        // Bootstrap listens for the arrows on `document` to walk the menu's own `.dropdown-item`s,
        // which would take the focus straight out of the field - the cursor below moves instead.
        event.preventDefault();
        event.stopPropagation();

        const current = results.findIndex((result) => result.classList.contains('is-current'));
        const next = event.key === 'ArrowDown'
            ? Math.min(current + 1, results.length - 1)
            : Math.max(current - 1, 0);

        results.forEach((result, index) => result.classList.toggle('is-current', index === next));
    }

    /**
     * Pins the width the columns gave the panel for as long as it stays open: the results grid is
     * narrower than they are, and a panel that shrank under the first keystroke would move the
     * entries the eye is still on.
     *
     * Horizontal placement is CSS's alone - the panel is centred on the bar, so it cannot run off
     * either edge and nothing has to be shifted back. Below the navbar-expand breakpoint it stacks
     * statically inside the collapsed menu, where it takes the width of that menu and there is no
     * geometry to pin.
     */
    #fit() {
        this.element.style.width = '';

        if (window.getComputedStyle(this.element).position !== 'absolute') {
            return;
        }

        this.element.style.width = `${this.element.getBoundingClientRect().width}px`;
    }

    #collect() {
        return [...this.gridTarget.querySelectorAll('.cm-toolsmenu__col')].flatMap((column) => {
            const group = column.querySelector('.dropdown-header').textContent.trim();

            return [...column.querySelectorAll('a.dropdown-item')].map((link) => ({
                label: link.textContent.trim(),
                href: link.getAttribute('href'),
                group,
                haystack: this.#normalize(link.textContent.trim()),
            }));
        });
    }

    /** A result names its group under its label: two libraries can read alike out of their column. */
    #result(tool) {
        const link = document.createElement('a');

        link.className = 'cm-toolsmenu__result';
        link.href = tool.href;
        link.append(
            this.#span('cm-toolsmenu__result-label', tool.label),
            this.#span('cm-toolsmenu__result-group', tool.group),
        );

        return link;
    }

    #span(className, text) {
        const span = document.createElement('span');

        span.className = className;
        span.textContent = text;

        return span;
    }

    #reset() {
        this.inputTarget.value = '';
        this.resultsTarget.replaceChildren();
        this.element.classList.remove('is-searching', 'is-empty');
    }

    /** Accent-insensitive: « séquences » has to answer to "sequences". */
    #normalize(value) {
        return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }
}
