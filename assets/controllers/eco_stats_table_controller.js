import { Controller } from '@hotwired/stimulus';

/**
 * The "Coureurs" block of the course statistics screen (1j): collapse/expand, the
 * Tous / Complets / Incomplets filter, and sorting by runner, time or distance.
 *
 * Everything happens on rows already in the DOM - the table is the whole class, never paginated,
 * so there is nothing to fetch and re-sorting is just a reorder of <tr> nodes.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'body', 'rows', 'row', 'filterOption', 'sortOption'];

    connect() {
        this.filterKey = 'all';
        this.sortKey = 'pseudo';
        this.ascending = true;
    }

    toggle() {
        const expanded = this.toggleTarget.getAttribute('aria-expanded') === 'true';
        this.toggleTarget.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        this.bodyTarget.hidden = expanded;
    }

    filter(event) {
        this.filterKey = event.params.filter;
        this.filterOptionTargets.forEach((option) => {
            option.classList.toggle('is-active', option.dataset.ecoStatsTableFilterParam === this.filterKey);
        });
        this.applyFilter();
    }

    sort(event) {
        const key = event.params.sort;
        // Re-clicking the active column flips the direction; a new column always starts ascending -
        // fastest time and shortest distance first, which is what the reader is after.
        this.ascending = key === this.sortKey ? !this.ascending : true;
        this.sortKey = key;

        this.sortOptionTargets.forEach((option) => {
            const isActive = option.dataset.ecoStatsTableSortParam === key;
            option.classList.toggle('is-active', isActive);
            const arrow = option.querySelector('.eco-sort__arrow');
            if (arrow) {
                arrow.textContent = isActive ? (this.ascending ? '▲' : '▼') : '⇅';
            }
        });

        const sorted = [...this.rowTargets].sort((a, b) => this.compare(a, b));
        sorted.forEach((row) => this.rowsTarget.appendChild(row));
    }

    applyFilter() {
        this.rowTargets.forEach((row) => {
            const complete = row.dataset.complete === '1';
            const visible = this.filterKey === 'all'
                || (this.filterKey === 'complete' && complete)
                || (this.filterKey === 'incomplete' && !complete);
            row.hidden = !visible;
        });
    }

    compare(a, b) {
        const direction = this.ascending ? 1 : -1;

        if (this.sortKey === 'pseudo') {
            return direction * a.dataset.pseudo.localeCompare(b.dataset.pseudo, 'fr');
        }

        const left = this.numericValue(a);
        const right = this.numericValue(b);

        // A runner with no time (never finished) has nothing to rank, so they sink to the bottom
        // whichever way the column is sorted rather than topping an ascending sort with a zero.
        if (left === null && right === null) return 0;
        if (left === null) return 1;
        if (right === null) return -1;

        return direction * (left - right);
    }

    numericValue(row) {
        const raw = this.sortKey === 'duration' ? row.dataset.duration : row.dataset.distance;

        return raw === '' || raw === undefined ? null : Number(raw);
    }
}
