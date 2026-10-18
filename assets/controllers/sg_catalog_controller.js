import { Controller } from '@hotwired/stimulus';

/**
 * The filters of the serious games' catalogue (design/validated/serious-games.md §8): a search on
 * title and pitch, subject and level chips, progress. OR inside a group, AND between groups; the
 * chips are the union of the tags of the games shown, never a list to maintain.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['search', 'chip', 'status', 'card', 'count', 'empty', 'clear'];

    static values = { countLabels: Object };

    toggle(event) {
        const chip = event.currentTarget;
        chip.setAttribute('aria-pressed', 'true' === chip.getAttribute('aria-pressed') ? 'false' : 'true');
        this.filter();
    }

    status(event) {
        this.statusTargets.forEach((button) => button.setAttribute('aria-pressed', button === event.currentTarget ? 'true' : 'false'));
        this.filter();
    }

    clear() {
        this.searchTarget.value = '';
        this.chipTargets.forEach((chip) => chip.setAttribute('aria-pressed', 'false'));
        this.statusTargets.forEach((button) => button.setAttribute('aria-pressed', 'all' === button.dataset.status ? 'true' : 'false'));
        this.filter();
    }

    filter() {
        const query = this.searchTarget.value.trim().toLowerCase();
        const chosen = (group) => this.chipTargets.filter((c) => c.dataset.group === group && 'true' === c.getAttribute('aria-pressed')).map((c) => c.dataset.value);
        const subjects = chosen('subject');
        const levels = chosen('level');
        const status = this.statusTargets.find((b) => 'true' === b.getAttribute('aria-pressed'))?.dataset.status ?? 'all';

        let shown = 0;
        this.cardTargets.forEach((card) => {
            const cardSubjects = JSON.parse(card.dataset.subjects);
            const cardLevels = JSON.parse(card.dataset.levels);
            const visible = ('' === query || card.dataset.text.includes(query))
                && (0 === subjects.length || subjects.some((s) => cardSubjects.includes(s)))
                && (0 === levels.length || levels.some((l) => cardLevels.includes(l)))
                && ('all' === status || card.dataset.status === status);
            card.hidden = !visible;
            shown += visible ? 1 : 0;
        });

        this.emptyTarget.hidden = shown > 0;
        this.countTarget.textContent = `${shown} ${1 < shown ? 'jeux' : 'jeu'}`;
        this.clearTarget.hidden = '' === query && 0 === subjects.length && 0 === levels.length && 'all' === status;
    }
}
