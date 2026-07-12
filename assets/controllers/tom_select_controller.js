import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

/**
 * Generic ajax multi-select with removable tag pills: fetches matching options from a JSON
 * endpoint as the user types instead of the page ever rendering the full choice list
 * server-side (see App\Controller\ProgramAssignmentController::studentsSearch() for the first
 * caller). The endpoint must return `{results: [{id, text}], pagination: {more}}` - select2's
 * ajax response shape, reused here since it's a simple, self-describing format (this project
 * originally tried select2.js itself, but its minified bundle isn't safe to run under the
 * strict mode ES modules force - see git history for the diagnosis - so Tom Select, the modern
 * ES-module-native successor, is used instead for the same UX).
 *
 * data-controller is bound directly to the <select multiple> element; any <option selected>
 * already present when this connects (server-rendered, for the current value on an edit form)
 * becomes Tom Select's initial items automatically.
 */
export default class extends Controller {
    static values = {
        url: String,
        minimumInputLength: { type: Number, default: 1 },
        placeholder: { type: String, default: '' },
    };

    connect() {
        this.tomSelect = new TomSelect(this.element, {
            valueField: 'id',
            labelField: 'text',
            searchField: [],
            plugins: ['remove_button'],
            placeholder: this.placeholderValue,
            shouldLoad: (query) => query.length >= this.minimumInputLengthValue,
            load: (query, callback) => {
                const url = new URL(this.urlValue, window.location.origin);
                url.searchParams.set('q', query);

                fetch(url)
                    .then((response) => response.json())
                    .then((data) => callback(data.results))
                    .catch(() => callback());
            },
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
    }
}
