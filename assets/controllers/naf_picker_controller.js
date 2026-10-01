import { Controller } from '@hotwired/stimulus';

/*
 * The advanced « Codes NAF » filter of « Trouver une entreprise »: a word typed completes on the
 * INSEE's labels (« logiciel » → 58.29A, 58.29B…), a pick becomes a tag under the field, and the
 * codes travel in one hidden input, comma-separated - the server checks each against the
 * nomenclature again.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'list', 'tags', 'value'];
    static values = { url: String };

    connect() {
        this.timer = null;
        this.codes = this.valueTarget.value.split(',').map((code) => code.trim()).filter(Boolean);
        this.renderTags();
    }

    lookup() {
        clearTimeout(this.timer);
        const term = this.inputTarget.value.trim();
        if (term.length < 2) {
            this.close();
            return;
        }

        this.timer = setTimeout(async () => {
            const response = await fetch(this.urlValue + '?term=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                this.close();
                return;
            }
            this.show(await response.json());
        }, 200);
    }

    keydown(event) {
        if (event.key === 'Enter') {
            // Enter picks the first suggestion rather than submitting the whole search.
            const first = this.listTarget.querySelector('button');
            if (first && !this.listTarget.hidden) {
                event.preventDefault();
                first.click();
            }
        } else if (event.key === 'Escape') {
            this.close();
        }
    }

    show(items) {
        this.listTarget.replaceChildren();
        for (const item of items) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            const code = document.createElement('b');
            code.textContent = item.code;
            button.append(code, ' ' + item.label);
            button.addEventListener('click', () => this.add(item.code));
            li.appendChild(button);
            this.listTarget.appendChild(li);
        }
        this.listTarget.hidden = items.length === 0;
    }

    add(code) {
        if (!this.codes.includes(code)) {
            this.codes.push(code);
        }
        this.inputTarget.value = '';
        this.close();
        this.renderTags();
    }

    remove(event) {
        this.codes = this.codes.filter((code) => code !== event.params.code);
        this.renderTags();
    }

    renderTags() {
        this.valueTarget.value = this.codes.join(',');
        this.tagsTarget.replaceChildren();
        for (const code of this.codes) {
            const tag = document.createElement('span');
            tag.className = 'cm-cs-tag';
            tag.textContent = code + ' ';
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = '×';
            button.setAttribute('aria-label', code);
            button.dataset.action = 'naf-picker#remove';
            button.dataset.nafPickerCodeParam = code;
            tag.appendChild(button);
            this.tagsTarget.appendChild(tag);
        }
    }

    close() {
        this.listTarget.hidden = true;
        this.listTarget.replaceChildren();
    }
}
