import { Controller } from '@hotwired/stimulus';

// Drives the "Nouveau message" screen's cumulative audience picker (design/design_handoff_messagerie
// #2, see App\Form\MessageComposeType's docblock for the server-side half of this). Field
// selectors use name$="[...]" (ends-with) rather than hardcoding Symfony's generated form-root
// prefix (e.g. "message_compose[...]"), so this stays correct if that prefix ever changes.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['programPanel', 'programCount', 'programPills', 'manualPanel', 'counter', 'footerHint', 'sendButton'];
    static values = {
        countUrl: String,
        // Both baked server-side with a literal "__count__" placeholder (see
        // messages/compose.html.twig) so the real pluralized/translated copy only has to be
        // produced once, in PHP - this controller just swaps the number in.
        counterLabel: String,
        footerLabel: String,
    };

    connect() {
        this.refreshCount();
        this.updateProgramCount();
    }

    toggleProgramPanel(event) {
        this.programPanelTarget.classList.toggle('d-none', !event.target.checked);
    }

    toggleManualPanel(event) {
        this.manualPanelTarget.classList.toggle('d-none', !event.target.checked);
    }

    clearPrograms() {
        this.programPillsTarget.querySelectorAll('input[type="checkbox"]:checked').forEach((checkbox) => {
            checkbox.checked = false;
        });
        this.updateProgramCount();
        this.refreshCount();
    }

    expandPrograms() {
        this.programPillsTarget.classList.remove('is-collapsed');
    }

    updateProgramCount() {
        if (this.hasProgramCountTarget) {
            this.programCountTarget.textContent = String(this._checkedPrograms().length);
        }
    }

    refreshCount() {
        this.updateProgramCount();
        window.clearTimeout(this._debounce);
        this._debounce = window.setTimeout(() => this._fetchCount(), 200);
    }

    async _fetchCount() {
        const params = new URLSearchParams();
        ['audienceProgram', 'audienceAllStudents', 'audienceAllTeachers', 'audienceAllStaff', 'audienceManual'].forEach((field) => {
            const checkbox = this.element.querySelector(`input[name$="[${field}]"]`);
            if (checkbox?.checked) {
                params.append(field, '1');
            }
        });
        this._checkedPrograms().forEach((id) => params.append('programs[]', id));
        ['includeStudents', 'includeTeachers'].forEach((field) => {
            const checkbox = this.element.querySelector(`input[name$="[${field}]"]`);
            if (checkbox?.checked) {
                params.append(field, '1');
            }
        });
        this.element.querySelectorAll('select[name="recipients[]"] option:checked').forEach((option) => {
            params.append('recipients[]', option.value);
        });

        let count = 0;
        try {
            const response = await fetch(this.countUrlValue, { method: 'POST', body: params, headers: { Accept: 'application/json' } });
            if (response.ok) {
                ({ count } = await response.json());
            }
        } catch {
            // Network hiccup - leave the previous count/state showing rather than throw; the real
            // count is re-validated server-side at submit time regardless.
            return;
        }

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = this.counterLabelValue.replace('__count__', String(count));
        }
        if (this.hasFooterHintTarget) {
            this.footerHintTarget.innerHTML = this.footerLabelValue.replace('__count__', String(count));
        }
        if (this.hasSendButtonTarget) {
            this.sendButtonTarget.disabled = 0 === count;
        }
    }

    _checkedPrograms() {
        return Array.from(this.element.querySelectorAll('input[name$="[programs][]"]:checked')).map((input) => input.value);
    }
}
