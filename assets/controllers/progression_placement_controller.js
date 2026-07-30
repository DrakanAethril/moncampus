import { Controller } from '@hotwired/stimulus';

/**
 * Screen 2b - the créneau picker opened from a séance's 📅 button on 2a.
 *
 * Fetches the matière's créneaux on open rather than rendering the whole year up front, then
 * mirrors the design's two rules: the Dupliquer/Scinder choice only appears once at least two
 * créneaux are checked, and the submit button counts them.
 *
 * The urls are templated with a 0 placeholder for the séance id (Symfony's path() needs *some*
 * value at render time) and rebuilt per row - which is also why the dialog lives once per page
 * instead of once per séance.
 */
export default class extends Controller {
    static targets = ['dialog', 'form', 'title', 'subtitle', 'slots', 'mode', 'modeLegend', 'submit'];
    static values = { slotsUrl: String, associateUrl: String };

    openPicker(event) {
        const seanceId = event.currentTarget.dataset.seanceId;

        this.formTarget.action = this.associateUrlValue.replace(/\/0\/associate$/, `/${seanceId}/associate`);

        fetch(this.slotsUrlValue.replace(/\/0\/slots$/, `/${seanceId}/slots`))
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (!payload) {
                    return;
                }

                this.render(payload);
                this.dialogTarget.showModal();
            })
            .catch(() => {});
    }

    close() {
        this.dialogTarget.close();
    }

    render(payload) {
        this.titleTarget.textContent = this.titleTarget.dataset.template
            ? this.titleTarget.dataset.template.replace('%title%', payload.seance.title)
            : payload.seance.title;
        this.subtitleTarget.textContent = `${payload.seance.hours} h`;

        const selected = new Set(payload.selected.map(String));

        this.slotsTarget.replaceChildren(
            ...payload.slots.map((slot) => this.buildSlot(slot, selected.has(String(slot.id)))),
        );

        this.refresh();
    }

    buildSlot(slot, checked) {
        const label = document.createElement('label');
        label.className = 'cm-prog-picker__slot';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'sessions[]';
        input.value = slot.id;
        input.checked = checked;
        input.addEventListener('change', () => this.refresh());

        const body = document.createElement('span');

        const line = document.createElement('span');
        line.className = 'cm-prog-picker__slot-name';
        line.textContent = [this.formatDay(slot.day), `${slot.start} – ${slot.end}`, slot.room]
            .filter(Boolean)
            .join(' · ');

        const meta = document.createElement('span');
        meta.className = 'cm-prog-picker__slot-meta';
        // A busy créneau is labelled, never hidden or disabled - the design has no notion of slot
        // availability, and stacking two séances on one créneau by hand is explicitly allowed.
        meta.textContent = slot.takenBy ? `${slot.hours} h · ${slot.takenBy}` : `${slot.hours} h`;

        body.append(line, meta);
        label.append(input, body);

        return label;
    }

    refresh() {
        const count = this.checked().length;

        this.modeTarget.hidden = count < 2;
        this.modeLegendTarget.textContent = this.modeLegendTarget.dataset.template
            ? this.modeLegendTarget.dataset.template.replace('%count%', String(count))
            : '';

        this.submitTarget.textContent = this.submitTarget.dataset.template
            ? this.submitTarget.dataset.template.replace('%count%', String(count))
            : this.submitTarget.textContent;
        this.submitTarget.disabled = count === 0;
    }

    checked() {
        return Array.from(this.slotsTarget.querySelectorAll('input[type="checkbox"]:checked'));
    }

    formatDay(iso) {
        if (!iso) {
            return '';
        }

        const [year, month, day] = iso.split('-');

        return `${day}/${month}/${year}`;
    }
}
