import { Controller } from '@hotwired/stimulus';

// The "Outils > Tirage au sort" classroom roulette (design/design_campus_manager/reference/
// Tirage au sort.dc.html) - picks a random student, optionally filtered by Option and with/
// without replacement, with a decelerating slot-machine animation and a confetti winner overlay.
// Ported from the créa's React state machine into plain DOM/Stimulus since this app has no client
// framework - state lives on `this` instead of React state, and each mutation re-renders only the
// specific DOM bits that changed instead of a full re-render.
export default class extends Controller {
    static targets = [
        'slot', 'optionSelect', 'repeatSwitch', 'remaining', 'fsRemaining', 'winnerOverlay', 'winnerName', 'confetti',
    ];

    static values = {
        students: Array,
        labels: Object,
        durationSeconds: { type: Number, default: 4 },
    };

    connect() {
        this.allowRepeat = false;
        this.drawn = new Set();
        this.spinning = false;
        this.winner = null;
        this.optionFilter = 'all';
        this.spinTimeout = null;

        this.onFullscreenChange = () => {
            if (!document.fullscreenElement) {
                this.element.classList.remove('cm-draw-card--fs');
            }
        };
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        this.renderSlot();
        this.renderRemaining();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        clearTimeout(this.spinTimeout);
    }

    // this.optionFilter/this.allowRepeat/this.drawn are plain instance fields, not Stimulus
    // `static values` - they change many times per second during the spin animation, and going
    // through setXxxValue()'s attribute write (plus its xxxValueChanged() callback) on every tick
    // would be wasteful for state that's purely internal to this controller.
    get pool() {
        const students = this.optionFilter === 'all'
            ? this.studentsValue
            : this.studentsValue.filter((student) => student.optionIds.includes(Number(this.optionFilter)));
        const names = students.map((student) => student.name);

        return this.allowRepeat ? names : names.filter((name) => !this.drawn.has(name));
    }

    setOption(event) {
        if (this.spinning) {
            return;
        }
        this.optionFilter = event.target.value;
        this.winner = null;
        this.renderSlot();
        this.renderRemaining();
    }

    toggleRepeat() {
        if (this.spinning) {
            return;
        }
        this.allowRepeat = !this.allowRepeat;
        this.repeatSwitchTarget.classList.toggle('cm-draw-switch--on', this.allowRepeat);
        this.renderRemaining();
    }

    reset() {
        if (this.spinning) {
            return;
        }
        this.drawn.clear();
        this.winner = null;
        this.renderSlot();
        this.renderRemaining();
    }

    toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
            return;
        }
        this.element.classList.add('cm-draw-card--fs');
        if (this.element.requestFullscreen) {
            this.element.requestFullscreen().catch(() => this.element.classList.remove('cm-draw-card--fs'));
        }
    }

    draw() {
        if (this.spinning || this.winner) {
            return;
        }
        const pool = this.pool;
        if (!pool.length) {
            return;
        }

        const winner = pool[Math.floor(Math.random() * pool.length)];
        this.spinning = true;

        const total = this.durationSecondsValue * 750;
        let elapsed = 0;
        let delay = 55;
        const tick = () => {
            if (elapsed >= total) {
                this.slotTarget.textContent = winner;
                this.spinTimeout = setTimeout(() => this.finish(winner), 400);
                return;
            }
            this.slotTarget.textContent = pool[Math.floor(Math.random() * pool.length)];
            delay *= 1.07;
            elapsed += delay;
            this.spinTimeout = setTimeout(tick, delay);
        };
        this.slotTarget.classList.add('cm-draw-slot--spinning');
        tick();
    }

    finish(winner) {
        this.drawn.add(winner);
        this.spinning = false;
        this.winner = winner;
        this.slotTarget.classList.remove('cm-draw-slot--spinning');
        this.renderRemaining();
        this.showWinnerOverlay(winner);
    }

    showWinnerOverlay(winner) {
        this.winnerNameTarget.textContent = winner;
        this.buildConfetti();
        this.winnerOverlayTarget.hidden = false;
    }

    closeWinner() {
        this.winnerOverlayTarget.hidden = true;
        this.winner = null;
        this.renderSlot();
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    }

    buildConfetti() {
        const colors = ['#c9a04e', '#1B6BA8', '#ffffff', '#7d99b0'];
        const pieces = document.createDocumentFragment();
        for (let i = 0; i < 28; i += 1) {
            const piece = document.createElement('div');
            piece.className = 'cm-draw-confetti-piece';
            piece.style.left = `${(i * 3.6) + 1}%`;
            piece.style.background = colors[i % colors.length];
            piece.style.animationDuration = `${2.4 + (i % 5) * 0.5}s`;
            piece.style.animationDelay = `${(i % 7) * 0.35}s`;
            piece.style.transform = `rotate(${i * 37}deg)`;
            pieces.appendChild(piece);
        }
        this.confettiTarget.replaceChildren(pieces);
    }

    renderSlot() {
        this.slotTarget.classList.remove('cm-draw-slot--spinning');
        this.slotTarget.textContent = this.pool.length ? this.labelsValue.ready : this.labelsValue.allDrawn;
    }

    renderRemaining() {
        const pool = this.pool;
        const total = this.studentsValue.length;
        const done = this.drawn.size;
        let text;

        if (this.allowRepeat) {
            text = this.labelsValue.remainingWithRepeat.replace('%total%', total);
        } else if (!pool.length) {
            text = this.labelsValue.remainingAllDrawn;
        } else {
            const studentWord = pool.length > 1 ? this.labelsValue.studentWordPlural : this.labelsValue.studentWordSingular;
            const drawnWord = done > 1 ? this.labelsValue.drawnWordPlural : this.labelsValue.drawnWordSingular;
            text = this.labelsValue.remainingPoolTemplate
                .replace('%count%', pool.length)
                .replace('%studentWord%', studentWord)
                .replace('%done%', done)
                .replace('%drawnWord%', drawnWord);
        }

        this.remainingTarget.textContent = text;
        this.fsRemainingTarget.textContent = text;
    }
}
