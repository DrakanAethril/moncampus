import WidgetController, { randomItem } from '../class_board/widget_controller.js';

// Tirage au sort of the virtual board: the random draw tool's list, option filter and replacement
// rule. Linked to a saved draw, each change is written into the draw's own history through the
// tool's route (app_program_tools_random_draw_update) - the tool and the board then show the same
// « déjà tirés ». Without one, the history lives in the page only.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['root', 'name', 'count', 'drawn', 'optionPill', 'repeatPill'];

    setup() {
        const data = this.rootTarget.dataset;
        this.students = JSON.parse(data.students || '[]');
        this.drawnIds = JSON.parse(data.drawn || '[]');
        this.optionId = data.option === '' ? null : parseInt(data.option, 10);
        this.allowRepeat = data.allowRepeat === '1';
        this.rolling = false;
        this.render();
    }

    teardown() {
        clearTimeout(this.rollTimer);
    }

    shortcut(key) {
        if (key === 'draw') {
            this.draw();
        }
    }

    pool() {
        return this.optionId === null
            ? this.students
            : this.students.filter((student) => student.optionIds.includes(this.optionId));
    }

    draw() {
        if (this.rolling) {
            return;
        }
        const pool = this.pool();
        const candidates = this.allowRepeat ? pool : pool.filter((student) => !this.drawnIds.includes(student.id));
        if (candidates.length === 0) {
            this.nameTarget.textContent = this.rootTarget.dataset.everyoneDrawn;
            return;
        }
        const winner = randomItem(candidates);
        const finish = () => {
            this.rolling = false;
            this.nameTarget.classList.remove('is-rolling');
            this.nameTarget.textContent = winner.name;
            if (!this.drawnIds.includes(winner.id)) {
                this.drawnIds.push(winner.id);
            }
            this.render(false);
            this.persist();
        };
        if (this.reducedMotion) {
            finish();
            return;
        }
        this.rolling = true;
        this.nameTarget.classList.add('is-rolling');
        let step = 0;
        const roll = () => {
            if (step++ < 14) {
                this.nameTarget.textContent = randomItem(pool).name;
                this.rollTimer = setTimeout(roll, 45 + step * 6);
                return;
            }
            finish();
        };
        roll();
    }

    restart() {
        this.drawnIds = [];
        this.nameTarget.textContent = '—';
        this.render();
        this.persist();
    }

    chooseOption(event) {
        const value = event.currentTarget.dataset.option;
        this.optionId = value === '' ? null : parseInt(value, 10);
        this.store({ optionId: this.optionId });
        this.render();
        this.persist();
    }

    toggleRepeat() {
        this.allowRepeat = !this.allowRepeat;
        this.store({ allowRepeat: this.allowRepeat });
        this.render();
        this.persist();
    }

    render(resetName = true) {
        if (resetName && !this.rolling && this.nameTarget.textContent === '') {
            this.nameTarget.textContent = '—';
        }
        this.optionPillTargets.forEach((pill) => {
            const value = pill.dataset.option === '' ? null : parseInt(pill.dataset.option, 10);
            pill.setAttribute('aria-pressed', String(value === this.optionId));
        });
        this.repeatPillTarget.setAttribute('aria-pressed', String(!this.allowRepeat));

        const pool = this.pool();
        const drawn = pool.filter((student) => this.drawnIds.includes(student.id));
        // In the order they were called, which is half of what the history is worth.
        const ordered = this.drawnIds.map((id) => drawn.find((student) => student.id === id)).filter(Boolean);
        this.countTarget.textContent = this.allowRepeat
            ? ''
            : this.rootTarget.dataset.countLabel.replace('__D__', String(ordered.length)).replace('__T__', String(pool.length));
        this.drawnTarget.replaceChildren(...ordered.map((student) => {
            const chip = document.createElement('span');
            chip.textContent = student.name;
            return chip;
        }));
    }

    // The saved draw's history, written by the tool's own route.
    persist() {
        const data = this.rootTarget.dataset;
        if (!data.updateUrl) {
            return;
        }
        fetch(data.updateUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf },
            body: JSON.stringify({ optionId: this.optionId, allowRepeat: this.allowRepeat, drawnIds: this.drawnIds }),
        }).catch(() => {});
    }
}
