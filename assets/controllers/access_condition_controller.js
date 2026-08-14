import { Controller } from '@hotwired/stimulus';

/*
 * The teacher's list of access conditions - design/comparaison/creas_espace_cours_conditions.html,
 * écran 4.
 *
 * The list lives here and is posted as one JSON field: a condition removed on screen is a condition
 * absent from the payload, so saving is one write and never a delete route per line. The shape it
 * writes is the one App\Service\AccessConditionTree::fromSubmitted() reads - the picked object
 * always under "target", whatever the type calls it once stored.
 */
export default class extends Controller {
    static targets = ['list', 'empty', 'payload', 'type', 'target', 'moment', 'date', 'min', 'max', 'note', 'error', 'add'];

    static values = {
        options: Object,
        labels: Object,
        needsTarget: Object,
        messages: Object,
        conditions: Array,
    };

    connect() {
        // Stimulus Array/Object values re-parse on every access - read once into a local, as the
        // gotcha documented in CLAUDE.md says.
        this.conditions = [...this.conditionsValue];
        this.options = this.optionsValue;
        this.labels = this.labelsValue;
        this.needsTarget = this.needsTargetValue;
        this.messages = this.messagesValue;
        this.touched = false;

        this.typeChanged();
        this.render();
    }

    /** Each type asks for its own fields; the ones it does not use are hidden, not left empty. */
    typeChanged() {
        const type = this.typeTarget.value;
        const options = this.options[type] ?? [];
        const wantsTarget = this.needsTarget[type] === true;

        this.targetTarget.innerHTML = '';

        /*
         * A type with nothing to point at keeps its select and says so, rather than hiding it. The
         * field used to vanish, and a vanished field reads as a missing feature: on a class with no
         * séance, "Séance passée" offered a row with nothing to fill and an "Ajouter" that refused
         * in silence.
         */
        if (wantsTarget && options.length === 0) {
            const none = document.createElement('option');
            none.value = '';
            none.textContent = this.messages.emptyTarget ?? '';
            // The select is narrow, so the reason travels on the note line under the builder - the
            // same place a séance already explains what it currently resolves to.
            none.dataset.note = this.messages.emptyTargetNote ?? '';
            none.disabled = true;
            none.selected = true;
            this.targetTarget.append(none);
        }

        options.forEach((option) => {
            const element = document.createElement('option');
            element.value = String(option.id);
            element.textContent = option.label;
            element.dataset.note = option.note ?? '';
            this.targetTarget.append(element);
        });

        this.targetTarget.hidden = !wantsTarget;
        this.momentTarget.hidden = type !== 'seance_passed';
        this.dateTarget.hidden = type !== 'date_from';
        this.minTarget.hidden = !['quiz_score', 'audio_listened', 'video_watched'].includes(type);
        this.maxTarget.hidden = type !== 'quiz_score';
        this.addTarget.disabled = wantsTarget && options.length === 0;

        // Switching type resets the row, so whatever was half-filled before is no longer pending.
        this.touched = false;
        this.clearError();
        this.showNote();
    }

    /** Any edit of a value field arms the row: see the note on the builder in the template. */
    touch(event) {
        if (event.target !== this.typeTarget) {
            this.touched = true;
        }
    }

    /*
     * What the chosen séance resolves to today, under the select. The teacher sees both what they
     * picked and what it is currently worth - which is what makes it visible that the condition
     * follows the slot rather than a date typed once.
     */
    showNote() {
        const selected = this.targetTarget.selectedOptions[0];
        this.noteTarget.textContent = selected?.dataset.note ?? '';
    }

    /**
     * Returns whether the row made it into the list. It used to refuse in silence, which is how a
     * teacher could fill the builder, save, and be told the conditions were recorded while nothing
     * had been.
     */
    add() {
        const type = this.typeTarget.value;
        const target = this.targetTarget.value === '' ? null : Number(this.targetTarget.value);

        if (this.needsTarget[type] === true && target === null) {
            this.showError(this.messages.missingTarget ?? '');

            return false;
        }

        if (type === 'date_from' && this.dateTarget.value === '') {
            this.showError(this.messages.missingDate ?? '');

            return false;
        }

        this.conditions.push({
            type,
            target,
            min_percent: this.minTarget.hidden || this.minTarget.value === '' ? null : Number(this.minTarget.value),
            max_percent: this.maxTarget.hidden || this.maxTarget.value === '' ? null : Number(this.maxTarget.value),
            at: type === 'date_from' ? this.dateTarget.value : null,
            moment: this.momentTarget.value,
        });

        this.minTarget.value = '';
        this.maxTarget.value = '';
        this.touched = false;
        this.clearError();
        this.render();

        return true;
    }

    /**
     * "Enregistrer" with a filled-in row that was never added used to save the list without it and
     * flash a success. The row is committed here instead, and an incomplete one stops the submit
     * with the reason rather than letting the screen lie about what it stored.
     */
    submit(event) {
        if (this.touched && !this.add()) {
            event.preventDefault();
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = message === '';
    }

    clearError() {
        this.showError('');
    }

    remove(event) {
        this.conditions.splice(Number(event.params.index), 1);
        this.render();
    }

    render() {
        this.listTarget.innerHTML = '';
        this.conditions.forEach((condition, index) => this.listTarget.append(this.row(condition, index)));

        this.emptyTarget.hidden = this.conditions.length > 0;
        this.payloadTarget.value = JSON.stringify(this.conditions);
    }

    row(condition, index) {
        const row = document.createElement('div');
        row.className = 'cm-condition';

        const icon = document.createElement('span');
        icon.className = 'cm-condition__icon';
        icon.textContent = String(index + 1);

        const text = document.createElement('span');
        text.className = 'cm-condition__text';
        text.textContent = this.describe(condition);

        const note = this.noteOf(condition);
        if (note !== '') {
            const line = document.createElement('span');
            line.className = 'cm-condition__note';
            line.textContent = note;
            text.append(line);
        }

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'cm-condition__remove';
        remove.textContent = '✕';
        remove.dataset.action = 'access-condition#remove';
        remove.dataset.accessConditionIndexParam = String(index);

        row.append(icon, text, remove);

        return row;
    }

    describe(condition) {
        const parts = [this.labels[condition.type] ?? condition.type];
        const named = (this.options[condition.type] ?? []).find((option) => option.id === condition.target);

        if (named) {
            parts.push(named.label);
        }

        if (condition.type === 'date_from' && condition.at) {
            parts.push(condition.at.replace('T', ' '));
        }

        if (condition.min_percent !== null && condition.min_percent !== undefined) {
            parts.push(`≥ ${condition.min_percent} %`);
        }

        if (condition.max_percent !== null && condition.max_percent !== undefined) {
            parts.push(`≤ ${condition.max_percent} %`);
        }

        return parts.join(' · ');
    }

    noteOf(condition) {
        if (condition.type !== 'seance_passed') {
            return '';
        }

        const named = (this.options.seance_passed ?? []).find((option) => option.id === condition.target);

        return named?.note ?? '';
    }
}
