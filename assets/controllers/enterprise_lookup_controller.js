import { Controller } from '@hotwired/stimulus';

/*
 * « Ajouter un accueil », step 1: one search box, two answers - the employers already in the
 * vivier (a link to step 2) and the establishments of the État's register (a POST that makes one
 * an employer of ours, its SIRET confirmed by this very choice - vivier spec, R1).
 * Everything coming back is written with textContent: names come from the register.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'results', 'department'];
    static values = {
        url: String, registryAction: String, token: String,
        poolLabel: String, registryLabel: String, unavailableLabel: String, emptyLabel: String,
    };

    connect() {
        this.timer = null;
    }

    lookup() {
        clearTimeout(this.timer);
        const term = this.inputTarget.value.trim();
        if (term.length < 2) {
            this.resultsTarget.replaceChildren();
            return;
        }

        this.timer = setTimeout(async () => {
            const department = this.hasDepartmentTarget ? this.departmentTarget.value.trim() : '';
            const response = await fetch(this.urlValue + '?term=' + encodeURIComponent(term) + '&dep=' + encodeURIComponent(department), { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            this.render(await response.json());
        }, 300);
    }

    render(data) {
        const blocks = [];

        if (data.pool.length > 0) {
            blocks.push(this.heading(this.poolLabelValue));
            for (const item of data.pool) {
                const link = document.createElement('a');
                link.className = 'cm-cs-lookup__item';
                link.href = item.url;
                link.append(this.line(item.name, item.detail));
                blocks.push(link);
            }
        }

        blocks.push(this.heading(this.registryLabelValue));
        if (data.unavailable) {
            blocks.push(this.note(this.unavailableLabelValue));
        }
        for (const item of data.registry) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = this.registryActionValue;
            form.className = 'cm-cs-lookup__item';
            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = this.tokenValue;
            const siret = document.createElement('input');
            siret.type = 'hidden';
            siret.name = 'siret';
            siret.value = item.siret;
            const button = document.createElement('button');
            button.type = 'submit';
            button.append(this.line(item.name, item.detail));
            form.append(token, siret, button);
            blocks.push(form);
        }
        if (data.pool.length === 0 && data.registry.length === 0 && !data.unavailable) {
            blocks.push(this.note(this.emptyLabelValue));
        }

        this.resultsTarget.replaceChildren(...blocks);
    }

    heading(text) {
        const heading = document.createElement('div');
        heading.className = 'cm-cs-lookup__heading';
        heading.textContent = text;
        return heading;
    }

    note(text) {
        const note = document.createElement('p');
        note.className = 'cm-cs-card__sub';
        note.textContent = text;
        return note;
    }

    line(name, detail) {
        const fragment = document.createDocumentFragment();
        const strong = document.createElement('strong');
        strong.textContent = name;
        const small = document.createElement('span');
        small.textContent = detail || '';
        fragment.append(strong, small);
        return fragment;
    }
}
