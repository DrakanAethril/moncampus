import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

/**
 * Generic Tom Select wrapper covering two shapes used across the app:
 *
 * - Ajax search (no data-tom-select-create-value): fetches matching options from a JSON endpoint
 *   as the user types instead of the page ever rendering the full choice list server-side (see
 *   App\Controller\ProgramAssignmentController::studentsSearch() for the first caller). The
 *   endpoint must return `{results: [{id, text}], pagination: {more}}` - select2's ajax response
 *   shape, reused here since it's a simple, self-describing format (this project originally tried
 *   select2.js itself, but its minified bundle isn't safe to run under the strict mode ES modules
 *   force - see git history for the diagnosis - so Tom Select, the modern ES-module-native
 *   successor, is used instead for the same UX).
 * - Local create-or-reuse (data-tom-select-create-value="true", no url value): searches only the
 *   <option>s already rendered into the <select> (a teacher's own existing tags - see
 *   App\Entity\AbstractLibraryTag) and lets the user create a brand new one by typing a label
 *   that doesn't match; the submitted value is the label text itself either way, resolved
 *   server-side by App\Service\LibraryTagResolver.
 *
 * data-controller is bound directly to the <select> (single or multiple, both work); any
 * <option selected> already present when this connects (server-rendered, for the current value
 * on an edit form) becomes Tom Select's initial item(s) automatically.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: { type: String, default: '' },
        minimumInputLength: { type: Number, default: 1 },
        placeholder: { type: String, default: '' },
        create: { type: Boolean, default: false },
    };

    connect() {
        const config = {
            // Failing an explicit placeholder, the <select>'s own: Symfony renders its 'placeholder'
            // option as an <option value="">, which Tom Select hides when taking over the list -
            // without this fallback, a field that displayed "Choisissez un état" ends up empty once
            // enriched.
            placeholder: this.placeholderValue || this.element.querySelector('option[value=""]')?.textContent.trim() || '',
            plugins: ['remove_button'],
            create: this.createValue,
        };

        if ('' !== this.urlValue) {
            config.valueField = 'id';
            config.labelField = 'text';
            config.searchField = [];
            config.shouldLoad = (query) => query.length >= this.minimumInputLengthValue;
            config.load = (query, callback) => {
                const url = new URL(this.urlValue, window.location.origin);
                url.searchParams.set('q', query);

                fetch(url)
                    .then((response) => response.json())
                    .then((data) => {
                        // Drop the previous query's results before adding this one's. searchField
                        // is [] above (the endpoint, not the browser, decides what matches - it
                        // can match on fields the label doesn't even show, e.g. a tutor's email
                        // behind a "Nom — Entreprise" label), so Tom Select never filters options
                        // out on its own: without this, every option ever loaded stays in the
                        // dropdown and typing only highlights the matches among them instead of
                        // narrowing the list. clearOptions()'s default filter keeps whatever is
                        // currently selected, so an edit form doesn't lose its own value here.
                        this.tomSelect?.clearOptions();
                        callback(data.results);
                    })
                    // Deliberately not clearing on failure - a transient network blip shouldn't
                    // empty a list the user is reading.
                    .catch(() => callback());
            };
        }

        // Once a choice is made, the input that found it has no reason to remain: on a multiple
        // field, one almost always goes straight on to a second name, and keeping « cha » forced the
        // user to erase it by hand before searching for the next one. Tom Select leaves the text in
        // place as soon as a load function is supplied - it does not know whether the remote search
        // is still relevant - so it is up to us to clear it, and to reopen the list on that blank.
        if (this.element.multiple) {
            config.onItemAdd = function () {
                this.setTextboxValue('');
                this.refreshOptions(false);
            };
        }

        // Color chip before the label, when the <option> elements carry a data-color (equipment
        // conditions: see mockups 25b/25e/25f of the UFA handoff, where the condition reads from its
        // color before it reads from its name). With no data-color, standard rendering.
        if (this.element.querySelector('option[data-color]')) {
            const withDot = (data, escape) => {
                const option = this.element.querySelector(`option[value="${CSS.escape(data.value)}"]`);
                const color = option?.dataset.color;

                return `<div>${color ? `<span class="cm-select-dot" style="background: ${escape(color)}"></span>` : ''}${escape(data.text)}</div>`;
            };

            config.render = { option: withDot, item: withDot };
        }

        this.tomSelect = new TomSelect(this.element, config);

        // Tom Select's own "change" is an internal emitter event, not a DOM one: it writes the
        // value into the underlying <select> without ever dispatching anything the page can hear,
        // so a plain data-action="change->…" on an enhanced field silently never fires. Re-emit it
        // as the real DOM event everyone expects. No template paired the two before this, so
        // nothing can start firing twice - it only makes the obvious binding work.
        this.tomSelect.on('change', () => {
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
    }
}
