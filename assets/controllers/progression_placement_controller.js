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
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['dialog', 'form', 'title', 'subtitle', 'slots', 'mode', 'modeLegend', 'submit', 'coverage'];
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
        // Already rendered server-side ("55 min", "1 h 30") - see ProgressionController::slots().
        this.subtitleTarget.textContent = payload.seance.duration;

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
        meta.textContent = slot.takenBy ? `${slot.duration} · ${slot.takenBy}` : slot.duration;

        body.append(line, meta);

        // Only sent for a co-animated progression, and the reason it matters is geometric: two
        // créneaux on the same day at the same hour are otherwise the same pill twice, so picking
        // the wrong group is invisible. The server decides whether to send it (slots()).
        if (slot.group || slot.teacher) {
            const who = document.createElement('span');
            who.className = 'cm-prog-picker__slot-who';
            who.textContent = [slot.group, slot.teacher].filter(Boolean).join(' · ');
            body.append(who);
        }

        // Kept on the input so refresh() can read the coverage back off the checked boxes without
        // holding a second copy of the payload.
        input.dataset.group = slot.group || '';

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

        this.refreshCoverage();
    }

    /**
     * The groups on offer that no ticked créneau reaches. Same rule as the server's
     * App\Service\ProgressionCoAnimationCheck, and deliberately the same two silences: nothing
     * ticked says nothing (the séance is simply unplaced), and a ticked whole-class créneau covers
     * everybody.
     */
    refreshCoverage() {
        if (!this.hasCoverageTarget) {
            return;
        }

        const boxes = Array.from(this.slotsTarget.querySelectorAll('input[type="checkbox"]'));
        const offered = new Set(boxes.map((box) => box.dataset.group).filter(Boolean));
        const ticked = boxes.filter((box) => box.checked);

        const wholeClassTicked = ticked.some((box) => !box.dataset.group);
        const covered = new Set(ticked.map((box) => box.dataset.group).filter(Boolean));
        const missing = ticked.length === 0 || wholeClassTicked
            ? []
            : [...offered].filter((group) => !covered.has(group));

        this.coverageTarget.hidden = missing.length === 0;
        if (missing.length > 0) {
            this.coverageTarget.textContent = this.coverageTarget.dataset.template
                ? this.coverageTarget.dataset.template.replace('%groups%', missing.join(', '))
                : '';
        }
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
