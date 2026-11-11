import { Controller } from '@hotwired/stimulus';

/**
 * The « Nouveau dossier » and « Corriger le dossier » forms: the chosen formation says whether an
 * option is asked (and which) and, for a new dossier, which modality it starts in; the mail and the
 * name typed are checked against the year as one types (app_crm_new_check) - a mail already held in
 * the formation is refused, the same mail elsewhere or a namesake only said.
 */
export default class extends Controller {
    static targets = ['program', 'optionField', 'option', 'modality', 'modalityHint', 'check', 'last', 'first', 'mail'];
    static values = { programs: Object, checkUrl: String, current: String };

    connect() {
        this.programs = this.programsValue;
        this.sync(false);
    }

    changeProgram() {
        this.sync(true);
        this.check();
    }

    sync(changed) {
        const program = this.programs[this.programTarget.value];
        if (this.hasOptionFieldTarget) {
            const options = program?.options ?? [];
            const asks = !!program?.asksOption && options.length > 0;
            this.optionFieldTarget.hidden = !asks;
            if (changed) {
                const keep = this.programTarget.value === this.currentValue ? this.optionTarget.dataset.current : '';
                this.optionTarget.innerHTML = '';
                this.optionTarget.append(new Option(this.optionTarget.dataset.placeholder, ''));
                options.forEach((o) => this.optionTarget.append(new Option(`${o.label} · ${o.full}`, o.id, false, String(o.id) === keep)));
            }
        }
        if (changed && this.hasModalityTarget && program) {
            this.modalityTarget.value = program.modality;
        }
        if (this.hasModalityHintTarget) {
            this.modalityHintTarget.textContent = program ? this.modalityHintTarget.dataset.pattern.replace('__PROGRAM__', this.programTarget.selectedOptions[0].textContent) : this.modalityHintTarget.dataset.none;
        }
    }

    check() {
        if (!this.hasCheckTarget) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            const url = new URL(this.checkUrlValue, window.location.origin);
            url.searchParams.set('program', this.programTarget.value);
            url.searchParams.set('mail', this.mailTarget.value.trim());
            url.searchParams.set('last', this.lastTarget.value.trim());
            url.searchParams.set('first', this.firstTarget.value.trim());
            try {
                const response = await fetch(url, { headers: { Accept: 'text/html' } });
                if (response.ok) this.checkTarget.innerHTML = await response.text();
            } catch {
                // Nothing said: the server refuses a taken mail on saving anyway.
            }
        }, 250);
    }

    addGuardian(event) {
        event.currentTarget.closest('[data-guardian-add]').hidden = true;
        const block = this.element.querySelector('[data-guardian="2"]');
        block.hidden = false;
        block.querySelector('input')?.focus();
    }

    removeGuardian() {
        const block = this.element.querySelector('[data-guardian="2"]');
        block.querySelectorAll('input').forEach((input) => { input.value = ''; });
        block.hidden = true;
        this.element.querySelector('[data-guardian-add]').hidden = false;
    }
}
