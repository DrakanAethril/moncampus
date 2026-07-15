import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

// Searchable picker over the ~5000-icon Tabler set (assets/icons/tabler-icons-catalog.json,
// name+category+tags only - the actual path data lives once in assets/icons/tabler-sprite.svg and
// is referenced via <use>, never duplicated per option). Attaches directly to the plain text
// <input> Symfony renders for Section's "icon" field - Tom Select supports enhancing a text input
// the same way it does a <select>, so the submitted value stays the icon's name string with no
// separate hidden field needed. The catalog is fetched once and searched entirely client-side
// (no server round trip per keystroke, unlike the ajax mode in tom_select_controller.js) since
// ~750KB of JSON is cheap next to the sprite itself and this avoids a query endpoint.
export default class extends Controller {
    static values = {
        catalogUrl: String,
        spriteUrl: String,
        placeholder: { type: String, default: '' },
    };

    async connect() {
        const catalog = await fetch(this.catalogUrlValue).then((response) => response.json());
        const spriteUrl = this.spriteUrlValue;

        const renderOption = (data, escape) => `
            <div class="d-flex align-items-center gap-2">
                <svg class="icon"><use href="${escape(spriteUrl)}#tabler-icon-${escape(data.name)}"></use></svg>
                <span>${escape(data.name)}</span>
            </div>
        `;

        this.tomSelect = new TomSelect(this.element, {
            options: catalog,
            valueField: 'name',
            labelField: 'name',
            searchField: ['name', 'tags'],
            placeholder: this.placeholderValue,
            maxOptions: 200,
            plugins: ['remove_button'],
            render: {
                option: renderOption,
                item: renderOption,
                no_results: () => '<div class="no-results">Aucune icône trouvée</div>',
            },
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
    }
}
