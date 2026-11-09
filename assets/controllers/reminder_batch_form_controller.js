import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

/**
 * UFA › Relances › Nouvelle relance: one stage of the livret, then « Toutes les formations » or a
 * few of them, picked in a searchable select whose choices become tags under the field.
 *
 * Every change asks the server again for the count (ReminderBatchController::preview()): who waits,
 * how many mails would go, how many are set aside, when it should be done - and each formation's own
 * count, written beside it in the suggestions. The launch takes two clicks, the second one naming
 * the number of mails; the server then checks that number again before freezing the batch.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['step', 'scopeAll', 'picker', 'programs', 'tags', 'summary', 'expected', 'launch'];
    static values = { previewUrl: String, launchLabel: String, removeLabel: String, noResults: String };

    connect() {
        this.byProgram = {};
        this.armed = false;
        this.confirmLabel = '';
        this.labels = {};
        for (const option of this.programsTarget.options) {
            this.labels[option.value] = option.textContent.trim();
        }

        this.select = new TomSelect(this.programsTarget, {
            plugins: ['remove_button'],
            placeholder: this.programsTarget.dataset.placeholder || '',
            maxOptions: null,
            hideSelected: true,
            closeAfterSelect: false,
            render: {
                option: (data, escape) => {
                    const count = this.byProgram[data.value];
                    const hint = count === undefined ? '' : `<span class="cm-rb-option__count">${escape(String(count))}</span>`;

                    return `<div class="cm-rb-option"><span>${escape(data.text)}</span>${hint}</div>`;
                },
                // The choices live as tags under the field, not inside it.
                item: () => '<div class="cm-rb-hidden-item"></div>',
                no_results: (data, escape) => `<div class="no-results">${escape(this.noResultsValue)}</div>`,
            },
            onItemAdd() {
                this.setTextboxValue('');
                this.refreshOptions(false);
            },
        });
        this.select.on('change', () => {
            this.renderTags();
            this.refresh();
        });

        this.renderTags();
        this.refresh();
    }

    disconnect() {
        this.select?.destroy();
        clearTimeout(this.disarmTimer);
    }

    toggleScope() {
        this.pickerTarget.hidden = this.scopeAllTarget.checked;
        this.refresh();
    }

    renderTags() {
        this.tagsTarget.replaceChildren();
        for (const value of this.select.items) {
            const tag = document.createElement('span');
            tag.className = 'cm-rb-tag';
            tag.append(this.labels[value] ?? value);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'cm-rb-tag__remove';
            remove.setAttribute('aria-label', `${this.removeLabelValue} ${this.labels[value] ?? value}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => this.select.removeItem(value));
            tag.append(remove);

            this.tagsTarget.append(tag);
        }
    }

    async refresh() {
        this.disarm();
        this.launchTarget.disabled = true;

        const url = new URL(this.previewUrlValue, window.location.origin);
        url.searchParams.set('target', this.stepTarget.value);
        url.searchParams.set('scope', this.scopeAllTarget.checked ? 'all' : 'pick');
        for (const value of this.select.items) {
            url.searchParams.append('programs[]', value);
        }

        const request = (this.request = fetch(url, { headers: { Accept: 'application/json' } }));
        let data;
        try {
            const response = await request;
            if (!response.ok || request !== this.request) {
                return;
            }
            data = await response.json();
        } catch {
            return;
        }
        if (request !== this.request) {
            return;
        }

        if (data.target === null) {
            this.byProgram = {};
            this.expectedTarget.value = '0';
            this.summaryTarget.innerHTML = '';
            this.select.clearCache();
            return;
        }

        this.byProgram = data.byProgram;
        this.select.clearCache();
        this.select.refreshOptions(false);
        this.summaryTarget.innerHTML = data.summary;
        this.expectedTarget.value = String(data.toSend);
        this.confirmLabel = data.confirmLabel;
        this.launchTarget.disabled = data.toSend === 0;
    }

    launch(event) {
        if (this.armed) {
            return;
        }

        event.preventDefault();
        this.armed = true;
        this.launchTarget.textContent = this.confirmLabel;
        this.disarmTimer = setTimeout(() => this.disarm(), 6000);
    }

    disarm() {
        clearTimeout(this.disarmTimer);
        if (!this.armed) {
            return;
        }
        this.armed = false;
        this.launchTarget.textContent = this.launchLabelValue;
    }
}
