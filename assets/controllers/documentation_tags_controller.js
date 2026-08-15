import { Controller } from '@hotwired/stimulus';

// The tag field of the documentation editor (design_handoff_base_documentaire, écran 2d): chips
// above, a text input below, and a suggestion list that shows the existing tags with their usage
// count plus a last "Créer le tag « … »" line when nothing matches.
//
// The truth is the hidden field, one label per line: the chips are a rendering of it, and a
// browser that never runs this controller still submits whatever the field holds. Tags are a
// shared, free-text vocabulary created on the fly (App\Service\DocumentationTagResolver), which is
// why this is not the tomselect+ajax picker - that one is for Users.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['field', 'chips', 'input', 'suggestions'];
    static values = { url: String, createLabel: String };

    connect() {
        this.labels = this.fieldTarget.value.split('\n').map((label) => label.trim()).filter(Boolean);
        this.render();
    }

    async search() {
        const term = this.inputTarget.value.trim();
        const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const matches = (await response.json()).filter((match) => !this.has(match.label));
        this.renderSuggestions(matches, term);
    }

    keydown(event) {
        if ('Enter' !== event.key) {
            return;
        }

        // The field lives inside the article form: Enter must add a tag, never save the article.
        event.preventDefault();
        this.add(this.inputTarget.value);
    }

    pick(event) {
        this.add(event.currentTarget.dataset.label);
    }

    remove(event) {
        const label = event.currentTarget.dataset.label;
        this.labels = this.labels.filter((existing) => existing !== label);
        this.render();
    }

    add(rawLabel) {
        const label = (rawLabel ?? '').trim();

        if ('' !== label && !this.has(label)) {
            this.labels.push(label);
        }

        this.inputTarget.value = '';
        this.suggestionsTarget.hidden = true;
        this.render();
    }

    has(label) {
        const normalized = label.trim().toLowerCase();

        return this.labels.some((existing) => existing.toLowerCase() === normalized);
    }

    render() {
        this.fieldTarget.value = this.labels.join('\n');
        this.chipsTarget.innerHTML = '';

        this.labels.forEach((label) => {
            const chip = document.createElement('span');
            chip.className = 'cm-doc-pill cm-doc-pill--removable';
            chip.textContent = label;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'cm-doc-pill__remove';
            remove.dataset.label = label;
            remove.dataset.action = `${this.identifier}#remove`;
            remove.textContent = '×';
            chip.appendChild(remove);

            this.chipsTarget.appendChild(chip);
        });
    }

    renderSuggestions(matches, term) {
        this.suggestionsTarget.innerHTML = '';

        matches.forEach((match) => {
            const item = document.createElement('li');
            item.className = 'cm-doc-tags__suggestion';
            item.dataset.label = match.label;
            item.dataset.action = `click->${this.identifier}#pick`;
            item.innerHTML = `<span></span><span class="cm-doc-tags__count"></span>`;
            item.firstElementChild.textContent = match.label;
            item.lastElementChild.textContent = String(match.usages);
            this.suggestionsTarget.appendChild(item);
        });

        if ('' !== term && !matches.some((match) => match.label.toLowerCase() === term.toLowerCase())) {
            const create = document.createElement('li');
            create.className = 'cm-doc-tags__suggestion cm-doc-tags__suggestion--create';
            create.dataset.label = term;
            create.dataset.action = `click->${this.identifier}#pick`;
            create.textContent = this.createLabelValue.replace('%tag%', term);
            this.suggestionsTarget.appendChild(create);
        }

        this.suggestionsTarget.hidden = 0 === this.suggestionsTarget.childElementCount;
    }
}
