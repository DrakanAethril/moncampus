import { Controller } from '@hotwired/stimulus';

// « Ta cote, duel après duel » on « Mes duels » (design/validated/quiz-tournament.md §10, mockup
// H-mes-duels). The chart is drawn by the server (App\Service\QuizTournament\QuizTournamentRatingChart);
// this adds the readout: a hairline that snaps to the duel nearest the pointer, that point lifted,
// and a tooltip with its rating. It enhances, never gates - every value is in the list below.
export default class extends Controller {
    static targets = ['svg', 'point', 'cross', 'tip'];

    move(event) {
        const svg = this.svgTarget;
        const box = svg.getBoundingClientRect();
        const view = svg.viewBox.baseVal;
        if (!box.width || !view.width) return;
        const x = ((event.clientX - box.left) / box.width) * view.width;

        let nearest = null;
        let distance = Infinity;
        this.pointTargets.forEach((point) => {
            const gap = Math.abs(Number(point.dataset.x) - x);
            if (gap < distance) {
                distance = gap;
                nearest = point;
            }
        });
        if (nearest) this.#show(nearest, box, view);
    }

    leave() {
        this.crossTarget.setAttribute('hidden', '');
        this.tipTarget.hidden = true;
        this.pointTargets.forEach((point) => point.classList.remove('is-hovered'));
    }

    #show(point, box, view) {
        const x = Number(point.dataset.x);
        const y = Number(point.dataset.y);
        this.pointTargets.forEach((other) => other.classList.toggle('is-hovered', other === point));
        this.crossTarget.setAttribute('x1', String(x));
        this.crossTarget.setAttribute('x2', String(x));
        this.crossTarget.removeAttribute('hidden');

        const value = document.createElement('strong');
        value.textContent = point.dataset.rating;
        const label = document.createElement('span');
        label.textContent = point.dataset.label;
        this.tipTarget.replaceChildren(value, label);
        this.tipTarget.hidden = false;

        const scale = box.width / view.width;
        const tipWidth = this.tipTarget.offsetWidth;
        const left = Math.min(Math.max(x * scale - tipWidth / 2, 0), box.width - tipWidth);
        this.tipTarget.style.left = `${left}px`;
        this.tipTarget.style.top = `${Math.max(y * scale - this.tipTarget.offsetHeight - 12, 0)}px`;
    }
}
