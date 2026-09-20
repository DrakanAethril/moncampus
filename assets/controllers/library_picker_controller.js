import { Controller } from '@hotwired/stimulus';

/*
 * Choosing an item in a library by walking its folders, instead of scrolling a <select>.
 *
 * The select is still the field: it keeps its name, its choices and the server-side rule about
 * them. This controller only writes into it and fires a `change`, so whatever else listens on that
 * select (quiz_pool_controller.js's pool total, quiz_launch_controller.js's defaults) goes on
 * working without knowing the modal exists.
 *
 * **One modal serves every field of the screen.** The tree is rendered once
 * (templates/_library_picker.html.twig); which items a field accepts is read off that field's own
 * <select> when the modal opens, so the two can never disagree - a quiz already in the pool is
 * absent from a merge row's options, and therefore absent from the modal that row opens.
 *
 * Written against `[data-picker-field]` / `[data-picker-item]` rather than against quizzes: the
 * file library's video picker is the same gesture over another tree.
 */
export default class extends Controller {
    static targets = ['modal', 'search', 'tree', 'empty'];

    connect() {
        this.field = null;
        this.trigger = null;
        this.allowed = new Set();

        // A form re-rendered after a failed submit comes back with its select already set, and the
        // button is what says so.
        this.fields.forEach((field) => this.sync(field));
    }

    open(event) {
        event.preventDefault();

        const field = event.currentTarget.closest('[data-picker-field]');
        const select = field === null ? null : field.querySelector('select');
        if (select === null) {
            return;
        }

        this.field = field;
        this.trigger = event.currentTarget;
        // The empty value is the placeholder, not a choice: the way to pick nothing is to not open
        // this modal at all.
        this.allowed = new Set(Array.from(select.options).map((option) => option.value).filter((value) => value !== ''));

        this.searchTarget.value = '';
        this.collapseAll();
        this.apply('');
        this.markCurrent(select.value);

        this.modalTarget.hidden = false;
        this.searchTarget.focus();
    }

    close(event) {
        if (event !== undefined) {
            event.preventDefault();
        }

        if (this.modalTarget.hidden) {
            return;
        }

        this.modalTarget.hidden = true;

        // Back to the button that opened it, never to the top of the page.
        if (this.trigger !== undefined && this.trigger !== null) {
            this.trigger.focus();
        }

        this.field = null;
        this.trigger = null;
    }

    // Clicking the backdrop, not a click anywhere inside the box - the modal element *is* the
    // backdrop, so the test is on the target rather than on a stopPropagation in the box.
    backdrop(event) {
        if (event.target === this.modalTarget) {
            this.close(event);
        }
    }

    choose(event) {
        event.preventDefault();

        const row = event.currentTarget.closest('[data-picker-item]');
        const select = this.field === null ? null : this.field.querySelector('select');
        if (row === null || select === null) {
            return;
        }

        select.value = row.dataset.value;
        // The field decides what the choice means; the modal only says which one was made.
        select.dispatchEvent(new Event('change', { bubbles: true }));
        this.sync(this.field);

        this.close(event);
    }

    toggle(event) {
        event.preventDefault();

        const folder = event.currentTarget.closest('[data-picker-folder]');
        if (folder === null) {
            return;
        }

        const collapsed = folder.classList.toggle('is-collapsed');
        event.currentTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    search() {
        this.apply(this.searchTarget.value);
    }

    // What is shown: the items this field accepts, narrowed by the search, and the folders that
    // lead to one of them. A folder holding nothing visible is a dead end, so it goes too.
    apply(term) {
        const needle = term.trim().toLowerCase();

        this.itemRows.forEach((row) => {
            const label = (row.dataset.label || '').toLowerCase();
            const matches = needle === '' || label.includes(needle);
            row.hidden = !(this.allowed.has(row.dataset.value) && matches);
        });

        // Deepest first: a folder's own visibility depends on its children's, which must already
        // have been decided (querySelectorAll answers in document order, parents first).
        this.folderRows.reverse().forEach((folder) => {
            folder.hidden = folder.querySelector(':scope > .cm-picker__tree > li:not([hidden])') === null;

            // A search opens what it found; without one the tree goes back to closed.
            if (needle !== '') {
                folder.classList.remove('is-collapsed');
            }
        });

        this.emptyTarget.hidden = this.treeTarget.querySelector(':scope > li:not([hidden])') !== null;
    }

    // The modal opens on where the field already is - the quiz it holds, in the folder it is filed
    // under, rather than at the top of a tree the teacher then has to search for it in.
    markCurrent(value) {
        this.itemRows.forEach((row) => row.classList.remove('is-current'));

        if (value === '') {
            return;
        }

        const current = this.itemRows.find((row) => row.dataset.value === value);
        if (current === undefined) {
            return;
        }

        current.classList.add('is-current');

        let folder = current.closest('[data-picker-folder]');
        while (folder !== null) {
            folder.classList.remove('is-collapsed');
            folder = folder.parentElement === null ? null : folder.parentElement.closest('[data-picker-folder]');
        }

        current.scrollIntoView({ block: 'center' });
    }

    collapseAll() {
        this.folderRows.forEach((folder) => {
            folder.classList.add('is-collapsed');
            const row = folder.querySelector(':scope > .cm-picker__folder-row');
            if (row !== null) {
                row.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // What the button says: the name of the chosen item, or the placeholder while there is none.
    sync(field) {
        const select = field.querySelector('select');
        const label = field.querySelector('[data-picker-label]');
        if (select === null || label === null) {
            return;
        }

        const option = select.selectedOptions[0];
        const chosen = option !== undefined && option.value !== '';

        label.textContent = chosen ? option.textContent.trim() : (field.dataset.pickerPlaceholder || '');
        field.classList.toggle('is-empty', !chosen);
    }

    get fields() {
        return Array.from(this.element.querySelectorAll('[data-picker-field]'));
    }

    get itemRows() {
        return Array.from(this.treeTarget.querySelectorAll('[data-picker-item]'));
    }

    get folderRows() {
        return Array.from(this.treeTarget.querySelectorAll('[data-picker-folder]'));
    }
}
