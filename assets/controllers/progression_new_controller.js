import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Screen 3c - the progression creation form.
 *
 * Three jobs, all of them things the server genuinely cannot do: narrow the matière radios to the
 * picked class, reveal step 5 when "Depuis mes séquences" is chosen, and manage the reorderable
 * list of séquences fed by an autocompletion restricted to instantiated séquences (never library
 * templates - the endpoint enforces that, this only displays it).
 *
 * The row order IS the placement order, so the three parallel arrays (sequences[],
 * sequenceStartDates[], sequencePlaced[]) are read positionally server-side. Dragging a row moves
 * the whole <div>, which keeps the three in step automatically - which is exactly why the "Placer
 * dans l'EDT" state travels in a hidden input rather than as a checkbox (an unchecked checkbox
 * posts nothing and would silently shift every following row's flag by one).
 */
export default class extends Controller {
    static targets = ['cohort', 'topic', 'sequencesStep', 'rows', 'rowTemplate', 'search', 'results'];
    static values = { searchUrl: String };

    connect() {
        this.sortable = Sortable.create(this.rowsTarget, {
            handle: '.sortable-reorder-handle',
            animation: 150,
            onEnd: () => this.renumber(),
        });

        this.applyCohort(this.cohortTargets[0]?.dataset.cohortId ?? null);
    }

    disconnect() {
        this.sortable?.destroy();
    }

    pickCohort(event) {
        this.cohortTargets.forEach((chip) => chip.classList.toggle('is-active', chip === event.currentTarget));
        this.applyCohort(event.currentTarget.dataset.cohortId);
    }

    // Changing class invalidates every already-picked séquence (they belong to the previous
    // class's Program), so the list starts over rather than silently posting rows the server would
    // drop.
    applyCohort(cohortId) {
        this.topicTargets.forEach((row) => {
            const matches = row.dataset.cohortId === cohortId;
            row.hidden = !matches;

            const input = row.querySelector('input');
            if (input && !matches) {
                input.checked = false;
            }
        });

        this.rowsTarget.replaceChildren();
        this.hideResults();
    }

    pickTopic() {
        this.hideResults();
    }

    pickOrigin(event) {
        const fromSequences = event.currentTarget.value === 'sequences';
        this.sequencesStepTarget.hidden = !fromSequences;

        if (!fromSequences) {
            this.rowsTarget.replaceChildren();
        }
    }

    search() {
        const term = this.searchTarget.value.trim();
        const topicId = this.selectedTopicId();

        if (!topicId) {
            this.hideResults();
            return;
        }

        const url = new URL(this.searchUrlValue, window.location.origin);
        url.searchParams.set('topic', topicId);
        url.searchParams.set('q', term);

        fetch(url)
            .then((response) => (response.ok ? response.json() : { results: [] }))
            .then((payload) => this.renderResults(payload.results ?? []))
            .catch(() => this.hideResults());
    }

    renderResults(results) {
        const picked = new Set(this.pickedIds());
        const available = results.filter((result) => !picked.has(String(result.id)));

        this.resultsTarget.replaceChildren(
            ...available.map((result) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'cm-prog-search__result';
                item.textContent = result.text;

                const meta = document.createElement('span');
                // Rendered server-side ("3 h 20") - séance durations are minutes, see
                // ProgressionController::sequencesSearch().
                meta.textContent = `${result.duration} · ${result.seances}`;
                item.append(meta);

                item.addEventListener('click', () => this.addRow(result));

                return item;
            }),
        );

        this.resultsTarget.hidden = available.length === 0;
    }

    addRow(result) {
        const row = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);

        row.querySelector('[data-row-id]').value = result.id;
        row.querySelector('[data-row-title]').textContent = result.text;
        row.querySelector('[data-row-meta]').textContent = `${result.duration} · ${result.seances}`;

        this.rowsTarget.append(row);
        this.renumber();

        this.searchTarget.value = '';
        this.hideResults();
    }

    removeRow(event) {
        event.currentTarget.closest('[data-sequence-row]')?.remove();
        this.renumber();
    }

    togglePlaced(event) {
        const row = event.currentTarget.closest('[data-sequence-row]');
        row.querySelector('[data-row-placed]').value = event.currentTarget.checked ? '1' : '0';
    }

    renumber() {
        this.rows().forEach((row, index) => {
            row.querySelector('.cm-prog-row__index').textContent = String(index + 1);
        });
    }

    rows() {
        return Array.from(this.rowsTarget.querySelectorAll('[data-sequence-row]'));
    }

    pickedIds() {
        return this.rows().map((row) => row.querySelector('[data-row-id]').value);
    }

    selectedTopicId() {
        return this.element.querySelector('input[name="topic"]:checked')?.value ?? null;
    }

    hideResults() {
        this.resultsTarget.hidden = true;
        this.resultsTarget.replaceChildren();
    }
}
