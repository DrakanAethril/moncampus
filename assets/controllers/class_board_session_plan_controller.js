import WidgetController, { minutesSeconds } from '../class_board/widget_controller.js';

// Déroulé de séance of the virtual board: the phases of the séance (name and duration only), the
// current one with its time left and a bar, « Fin prévue dans… », previous / pause / next - which
// the arrow keys drive too, so a presentation clicker moves the plan along. Which phase is current
// is never saved: reopening the board starts the plan again.
//
// Durations are in MINUTES here, like a library séance's phases; the server converts the timetable's
// decimal hours once, before anything reaches this page.
const MAX_PHASES = 30;

/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['list', 'playIcon', 'pauseIcon', 'total', 'editor', 'addButton'];

    setup() {
        const data = this.listTarget.dataset;
        this.manual = data.manual === '1';
        this.phases = this.manual ? this.manualPhases() : JSON.parse(data.phases || '[]');
        this.index = 0;
        this.remaining = (this.phases[0]?.minutes || 0) * 60;
        this.running = false;
        this.endsAt = 0;
        this.render();
        this.renderEditor();
    }

    manualPhases() {
        return (Array.isArray(this.config.phases) ? this.config.phases : []).map((phase) => ({ name: phase.name || '', minutes: phase.minutes || 10 }));
    }

    tick() {
        if (!this.running || this.phases.length === 0) {
            return;
        }
        this.remaining = Math.max(0, (this.endsAt - Date.now()) / 1000);
        if (this.remaining === 0) {
            this.running = false;
            this.element.classList.add('is-ringing');
            this.render();
            return;
        }
        this.renderCurrent();
    }

    shortcut(key) {
        if (key === 'next') {
            this.next();
        } else if (key === 'previous') {
            this.previous();
        }
    }

    next() {
        this.go(1);
    }

    previous() {
        this.go(-1);
    }

    go(step) {
        if (this.phases.length === 0) {
            return;
        }
        this.element.classList.remove('is-ringing');
        this.index = Math.min(this.phases.length - 1, Math.max(0, this.index + step));
        this.remaining = this.phases[this.index].minutes * 60;
        this.endsAt = Date.now() + this.remaining * 1000;
        this.render();
    }

    toggle() {
        if (this.phases.length === 0) {
            return;
        }
        this.element.classList.remove('is-ringing');
        if (this.running) {
            this.running = false;
        } else {
            if (this.remaining === 0) {
                this.go(1);
            }
            this.running = true;
            this.endsAt = Date.now() + this.remaining * 1000;
        }
        this.render();
    }

    render() {
        const data = this.listTarget.dataset;
        if (this.phases.length === 0) {
            this.listTarget.innerHTML = '';
            const empty = document.createElement('li');
            empty.className = 'cm-cb-w__meta';
            empty.textContent = data.empty;
            this.listTarget.appendChild(empty);
        } else {
            this.listTarget.replaceChildren(...this.phases.map((phase, index) => {
                const item = document.createElement('li');
                item.className = `cm-cb-phase${index < this.index ? ' is-done' : ''}${index === this.index ? ' is-current' : ''}`;
                item.innerHTML = '<span class="cm-cb-phase__n"></span><span class="cm-cb-phase__name"></span><span class="cm-cb-phase__left"></span><span class="cm-cb-phase__bar"><i></i></span>';
                item.querySelector('.cm-cb-phase__n').textContent = String(index + 1);
                item.querySelector('.cm-cb-phase__name').textContent = phase.name;
                item.querySelector('.cm-cb-phase__left').textContent = data.minutes.replace('__N__', String(phase.minutes));
                return item;
            }));
        }
        this.playIconTarget.hidden = this.running;
        this.pauseIconTarget.hidden = !this.running;
        this.renderCurrent();
    }

    renderCurrent() {
        const current = this.listTarget.querySelector('.is-current');
        const phase = this.phases[this.index];
        if (current && phase) {
            current.querySelector('.cm-cb-phase__left').textContent = minutesSeconds(this.remaining);
            current.querySelector('.cm-cb-phase__bar i').style.width = `${(100 * (1 - this.remaining / (phase.minutes * 60))).toFixed(1)}%`;
        }
        const left = this.phases.slice(this.index + 1).reduce((sum, item) => sum + item.minutes, 0) + Math.ceil(this.remaining / 60);
        this.totalTarget.textContent = this.phases.length === 0 ? '' : this.totalTarget.dataset.label.replace('__N__', String(left));
    }

    // ------------------------------------------------------------ phases typed by hand

    renderEditor() {
        if (!this.hasEditorTarget || !this.manual) {
            return;
        }
        const data = this.editorTarget.dataset;
        this.editorTarget.replaceChildren(...this.phases.map((phase, index) => {
            const row = document.createElement('div');
            row.className = 'cm-cb-phase-editor__row';
            const name = document.createElement('input');
            Object.assign(name, { type: 'text', className: 'cm-cb-input', maxLength: 160, value: phase.name, placeholder: data.namePlaceholder });
            name.dataset.index = String(index);
            name.dataset.field = 'name';
            name.dataset.action = 'input->class-board-session-plan#editPhase';
            const minutes = document.createElement('input');
            Object.assign(minutes, { type: 'number', className: 'cm-cb-input', min: 1, max: 600, value: String(phase.minutes) });
            minutes.setAttribute('aria-label', data.minutesLabel);
            minutes.dataset.index = String(index);
            minutes.dataset.field = 'minutes';
            minutes.dataset.action = 'input->class-board-session-plan#editPhase';
            const remove = document.createElement('button');
            Object.assign(remove, { type: 'button', className: 'cm-cb-btn cm-cb-btn--small', textContent: '×' });
            remove.setAttribute('aria-label', data.removeLabel);
            remove.dataset.index = String(index);
            remove.dataset.action = 'class-board-session-plan#removePhase';
            row.append(name, minutes, remove);
            return row;
        }));
        if (this.hasAddButtonTarget) {
            this.addButtonTarget.hidden = this.phases.length >= MAX_PHASES;
        }
    }

    editPhase(event) {
        const field = event.target;
        const phase = this.phases[parseInt(field.dataset.index, 10)];
        if (field.dataset.field === 'name') {
            phase.name = field.value.slice(0, 160);
        } else {
            phase.minutes = Math.min(600, Math.max(1, parseInt(field.value, 10) || 1));
        }
        this.savePhases();
        this.render();
    }

    addPhase() {
        if (this.phases.length >= MAX_PHASES) {
            return;
        }
        this.phases.push({ name: '', minutes: 10 });
        this.savePhases();
        this.render();
        this.renderEditor();
        this.editorTarget.querySelector('.cm-cb-phase-editor__row:last-child input')?.focus();
    }

    removePhase(event) {
        this.phases.splice(parseInt(event.currentTarget.dataset.index, 10), 1);
        this.index = Math.min(this.index, Math.max(0, this.phases.length - 1));
        this.savePhases();
        this.render();
        this.renderEditor();
    }

    savePhases() {
        this.store({ phases: this.phases.map((phase) => ({ name: phase.name, minutes: phase.minutes })) });
    }
}
