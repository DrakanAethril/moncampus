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
export default class extends Controller {
    static values = {
        url: { type: String, default: '' },
        minimumInputLength: { type: Number, default: 1 },
        placeholder: { type: String, default: '' },
        create: { type: Boolean, default: false },
    };

    connect() {
        const config = {
            plugins: ['remove_button'],
            placeholder: this.placeholderValue,
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
                    .then((data) => callback(data.results))
                    .catch(() => callback());
            };
        }

        this.tomSelect = new TomSelect(this.element, config);
    }

    disconnect() {
        this.tomSelect?.destroy();
    }
}
